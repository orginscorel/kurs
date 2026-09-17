<?php

namespace App\Services\Imports;

use App\Exceptions\BusinessRuleException;
use App\Models\Guardian;
use App\Models\ImportJob;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Audit;
use App\Support\Sensitive;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel içe aktarma akışı: şablon → yükleme + önizleme (ImportJob "mapped") → onay (ImportJob "completed").
 * Yüklenen dosya kişisel veri içerdiği için özel diskte tutulur ve içe aktarma bitince silinir.
 */
class ImportService
{
    public const MAX_ROWS = 2000;

    private const DISK = 'local';

    public function importer(string $entity): RowImporter
    {
        return match ($entity) {
            'students' => new StudentImporter($this->lookup(...)),
            'teachers' => new TeacherImporter($this->lookup(...), $this->subjectIndex()),
            default => throw new BusinessRuleException('Bu veri türü için içe aktarma yok.', 'import_unknown_entity', [], 404),
        };
    }

    // ------------------------------------------------------------------ şablon

    public function writeTemplate(RowImporter $importer, string $target): void
    {
        $columns = $importer->columns();
        $writer = new Writer();
        $writer->openToFile($target);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName($importer->label().' listesi');
        $i = 1;
        foreach ($columns as $col) {
            $sheet->setColumnWidth((float) ($col['width'] ?? 16), $i++);
        }
        $header = (new Style())->withFontBold(true)->withBackgroundColor('E8EAF6');
        $writer->addRow(Row::fromValuesWithStyle(array_values(array_map(fn ($c) => $c['label'].(! empty($c['required']) ? ' *' : ''), $columns)), $header));

        $info = $writer->addNewSheetAndMakeItCurrent();
        $info->setName('Açıklamalar');
        $info->setColumnWidth(24, 1);
        $info->setColumnWidth(12, 2);
        $info->setColumnWidth(26, 3);
        $info->setColumnWidth(70, 4);
        $writer->addRow(Row::fromValuesWithStyle(['Sütun', 'Zorunlu', 'Örnek', 'Açıklama'], $header));
        foreach ($columns as $col) {
            $writer->addRow(Row::fromValues([$col['label'], ! empty($col['required']) ? 'Evet' : '', $col['example'] ?? '', $col['hint'] ?? '']));
        }
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Notlar', '', '', 'İlk sayfadaki başlık satırını değiştirmeyin; her öğe için bir satır doldurun. * işaretli sütunlar zorunludur.']));
        $writer->addRow(Row::fromValues(['', '', '', 'Sütun sırası önemli değildir; başlık adları eşleştirilir. Önizlemede hatalı satırlar içe aktarılmaz.']));
        $writer->addRow(Row::fromValues(['', '', '', 'TC kimlik ve telefon sütunlarının hücre biçimini "Metin" yapın; aksi halde Excel baştaki sıfırı silebilir (sistem yine de tanır).']));
        $writer->setCurrentSheet($writer->getSheets()[0]);
        $writer->close();
    }

    // ------------------------------------------------------------------ önizleme

    /** @return array{job: ImportJob, preview: array} */
    public function preview(RowImporter $importer, UploadedFile $file, int $userId): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            throw new BusinessRuleException('Yalnızca .xlsx (Excel) ya da .csv dosyası yükleyin.', 'import_bad_file');
        }

        $path = $file->storeAs('imports', Str::uuid()->toString().'.'.$ext, self::DISK);
        try {
            $result = $this->analyse($importer, Storage::disk(self::DISK)->path($path));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        $job = ImportJob::query()->create([
            'entity' => $importer->entity(), 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'mapping' => $result['mapping'], 'status' => 'mapped', 'total_rows' => $result['summary']['total'],
            'errors' => ['summary' => $result['summary']], 'created_by' => $userId,
        ]);

        return ['job' => $job, 'preview' => $result];
    }

    /**
     * Dosyayı okur, başlıkları eşler ve her satırı doğrular.
     *
     * @return array{mapping: array, unknown_columns: list<string>, rows: list<array>, summary: array{total:int, ok:int, warning:int, error:int}}
     */
    public function analyse(RowImporter $importer, string $absolutePath): array
    {
        [$headers, $rows] = $this->read($absolutePath);
        if ($headers === []) {
            throw new BusinessRuleException('Dosya boş görünüyor. Şablonu indirip ilk sayfaya verileri girin.', 'import_empty');
        }

        $mapped = $importer->mapHeaders($headers);
        if ($mapped['missing'] !== []) {
            throw new BusinessRuleException('Dosyada zorunlu sütun eksik: '.implode(', ', $mapped['missing']).'. Şablondaki başlıkları kullanın.', 'import_missing_columns', ['missing' => $mapped['missing']]);
        }
        if ($rows === []) {
            throw new BusinessRuleException('Başlık satırının altında veri yok.', 'import_no_rows');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new BusinessRuleException('Tek seferde en fazla '.self::MAX_ROWS.' satır içe aktarılabilir. Dosyayı bölün.', 'import_too_many_rows');
        }

        $seen = [];
        $out = [];
        $summary = ['total' => 0, 'ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($rows as [$rowNumber, $cells]) {
            $raw = [];
            foreach ($mapped['map'] as $index => $key) {
                $raw[$key] = $cells[$index] ?? null;
            }
            $v = $importer->validate($raw, $rowNumber, $seen);
            $status = $v['errors'] ? 'error' : ($v['warnings'] ? 'warning' : 'ok');
            $summary['total']++;
            $summary[$status]++;
            $out[] = ['row' => $rowNumber, 'status' => $status, 'title' => $v['title'], 'subtitle' => $v['subtitle'], 'errors' => $v['errors'], 'warnings' => $v['warnings'], 'data' => $v['data']];
        }

        return ['mapping' => $mapped['map'], 'unknown_columns' => $mapped['unknown'], 'rows' => $out, 'summary' => $summary];
    }

    // ------------------------------------------------------------------ onay

    /** @return array{created: list<array>, skipped: list<array>, failed: list<array>, secrets: list<array>} */
    public function commit(ImportJob $job, RowImporter $importer): array
    {
        $claimed = ImportJob::query()->whereKey($job->id)->where('status', 'mapped')->update(['status' => 'processing', 'updated_at' => now()]);
        if (! $claimed) {
            throw new BusinessRuleException('Bu içe aktarma zaten işlendi ya da süresi doldu. Dosyayı yeniden yükleyin.', 'import_not_pending', [], 409);
        }
        $disk = Storage::disk(self::DISK);
        if (! $job->path || ! $disk->exists($job->path)) {
            $job->forceFill(['status' => 'failed'])->save();
            throw new BusinessRuleException('Yüklenen dosya bulunamadı. Dosyayı yeniden yükleyin.', 'import_file_missing', [], 410);
        }

        @set_time_limit(300);
        $created = [];
        $skipped = [];
        $failed = [];
        $secrets = [];
        try {
            // Veritabanı önizlemeden sonra değişmiş olabilir: satırlar yeniden doğrulanır
            $analysis = $this->analyse($importer, $disk->path($job->path));
            foreach ($analysis['rows'] as $r) {
                if ($r['errors']) {
                    $skipped[] = ['row' => $r['row'], 'title' => $r['title'], 'reason' => $r['errors'][0]];

                    continue;
                }
                try {
                    // Satır, doğrulamadan hemen önce yeniden kontrol edilir (aynı dosyada kardeş/yinelenen)
                    $res = DB::transaction(fn () => $importer->import($r['data']));
                    $created[] = ['row' => $r['row'], 'id' => $res['id'], 'title' => $res['title'], 'note' => $res['note'] ?? null];
                    if (! empty($res['secret'])) {
                        $secrets[] = ['title' => $res['title'], 'note' => $res['note'] ?? null, 'password' => $res['secret']];
                    }
                } catch (BusinessRuleException $e) {
                    $failed[] = ['row' => $r['row'], 'title' => $r['title'], 'reason' => $e->getMessage()];
                } catch (\Throwable $e) {
                    report($e);
                    $failed[] = ['row' => $r['row'], 'title' => $r['title'], 'reason' => 'Beklenmeyen bir sorun nedeniyle kaydedilemedi.'];
                }
            }
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'errors' => ['message' => $e instanceof BusinessRuleException ? $e->getMessage() : 'İçe aktarma tamamlanamadı.']])->save();
            $disk->delete($job->path);
            throw $e;
        }

        $disk->delete($job->path);
        $job->forceFill([
            'status' => 'completed', 'success_rows' => count($created), 'total_rows' => count($created) + count($skipped) + count($failed),
            'errors' => ['skipped' => $skipped, 'failed' => $failed, 'created_ids' => array_column($created, 'id')],
        ])->save();

        Audit::log("import.{$importer->entity()}_completed", sprintf(
            'Excel\'den %s içe aktardı (%s): %d kayıt oluşturuldu, %d satır atlandı, %d satır kaydedilemedi.',
            mb_strtolower($importer->label()), $job->original_name, count($created), count($skipped), count($failed),
        ));

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'secrets' => $secrets];
    }

    /** Onaylanmayan önizlemeleri temizler (dosyalar kişisel veri içerir). */
    public function discard(ImportJob $job): void
    {
        if ($job->status !== 'mapped') {
            return;
        }
        Storage::disk(self::DISK)->delete($job->path);
        $job->forceFill(['status' => 'cancelled'])->save();
    }

    public function pruneStale(int $hours = 24): int
    {
        $n = 0;
        ImportJob::query()->withoutGlobalScopes()->where('status', 'mapped')->where('updated_at', '<', now()->subHours($hours))
            ->each(function (ImportJob $job) use (&$n) {
                Storage::disk(self::DISK)->delete($job->path);
                $job->forceFill(['status' => 'expired'])->save();
                $n++;
            });

        return $n;
    }

    // ------------------------------------------------------------------ yardımcılar

    /**
     * @return array{0: list<mixed>, 1: list<array{0:int, 1: list<mixed>}>}  başlıklar + [excel satır no, hücreler]
     */
    private function read(string $path): array
    {
        $isCsv = str_ends_with(strtolower($path), '.csv');
        if ($isCsv) {
            $sample = (string) file_get_contents($path, false, null, 0, 4096);
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ','));
        } else {
            $reader = new XlsxReader();
        }

        try {
            $reader->open($path);
        } catch (\Throwable) {
            throw new BusinessRuleException('Dosya okunamadı. Excel\'de "Farklı kaydet > Excel Çalışma Kitabı (.xlsx)" ile kaydedip tekrar deneyin.', 'import_unreadable');
        }

        $headers = [];
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $cells = $row->toArray();
                    if ($isCsv) {
                        $cells = array_map(fn ($c) => is_string($c) ? $this->toUtf8($c) : $c, $cells);
                    }
                    $nonEmpty = array_filter($cells, fn ($c) => $c !== null && trim((string) ($c instanceof \DateTimeInterface ? 'x' : $c)) !== '');
                    if ($headers === []) {
                        if ($nonEmpty !== []) {
                            $headers = $cells;
                        }

                        continue;
                    }
                    if ($nonEmpty === []) {
                        continue;
                    }
                    $rows[] = [$n, $cells];
                    if (count($rows) > self::MAX_ROWS) {
                        break;
                    }
                }
                break; // yalnız ilk sayfa
            }
        } finally {
            $reader->close();
        }

        return [$headers, $rows];
    }

    private function toUtf8(string $s): string
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);

        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1254');
    }

    /** Doğrulayıcıların veritabanı soruları (şube kapsamı model global scope'undan gelir). */
    private function lookup(string $kind, string $value): ?string
    {
        return match ($kind) {
            'national_id' => Student::query()->withTrashed()->whereIn('national_id_hash', Sensitive::hashes($value))->value('full_name'),
            'student_no' => Student::query()->withTrashed()->where('student_no', $value)->value('full_name'),
            'student_name' => Student::query()->where('full_name', $value)->value('student_no'),
            'username' => User::query()->withTrashed()->where('username', $value)->exists() ? $value : null,
            'guardian_phone' => ($id = StudentImporter::guardianIdByPhone($value)) ? $id.'|'.Guardian::query()->whereKey($id)->get(['first_name', 'last_name'])->first()?->full_name : null,
            'teacher_name' => Teacher::query()->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$value])->exists() ? $value : null,
            'teacher_phone' => Teacher::query()->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10) = ?", [substr(preg_replace('/\D/', '', $value), -10)])
                ->get(['first_name', 'last_name'])->first()?->full_name,
            default => null,
        };
    }

    /** @return array<string, int> */
    private function subjectIndex(): array
    {
        $index = [];
        foreach (Subject::query()->where('is_active', true)->get(['id', 'code', 'name', 'short_name']) as $s) {
            foreach ([$s->name, $s->code, $s->short_name] as $alias) {
                $key = ImportValue::fold($alias);
                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $s->id;
                }
            }
        }

        return $index;
    }
}
