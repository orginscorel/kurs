<?php

namespace App\Services\Exams\Optical;

use App\Exceptions\BusinessRuleException;
use App\Jobs\ProcessOpticalImport;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\OpticalImport;
use App\Models\OpticalLayout;
use App\Models\Student;
use App\Services\Exams\ExamResultService;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Optik okuma içe aktarma sihirbazı: yükle → eşleştir → önizle → içe aktar (kuyruk).
 * Her satır ExamResultService::recordAnswers ile puanlanır; eşleşmeyen öğrenci no'lar raporlanır.
 */
class OpticalImportService
{
    public const SYNC_LIMIT = 150;   // bu satır sayısına kadar anında işlenir, üstü kuyruğa gider

    public const DISK = 'local';

    /** @var array<string,int>|null student_no (normalize) => id */
    private ?array $studentIndex = null;

    /** @var array<string,int>|null ad soyad (normalize) => id */
    private ?array $nameIndex = null;

    public function __construct(private readonly ExamResultService $results) {}

    public function upload(Exam $exam, UploadedFile $file, ?string $format = null): array
    {
        $this->assertImportable($exam);
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4096);
        $format = $format ?: OpticalParser::detectFormat($file->getClientOriginalName(), $head);
        $ext = $format === 'xlsx' ? 'xlsx' : ($format === 'json' ? 'json' : 'txt');

        $import = OpticalImport::query()->create([
            'exam_id' => $exam->id, 'format' => $format, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => 'pending', 'created_by' => Auth::id(),
        ]);
        $path = "optical/{$exam->id}/{$import->id}.{$ext}";
        Storage::disk(self::DISK)->putFileAs("optical/{$exam->id}", $file, "{$import->id}.{$ext}");
        $import->forceFill(['path' => $path])->save();

        $parsed = $this->parse($import);
        $import->forceFill(['total_rows' => count($parsed['rows'])])->save();

        return $this->describe($exam, $import, $parsed);
    }

    /** Yüklenmiş dosyayı yeniden okuyup sihirbaz için özet döner (kolonlar, örnek satırlar, önerilen eşleşme). */
    public function describe(Exam $exam, OpticalImport $import, ?array $parsed = null): array
    {
        $parsed ??= $this->parse($import);
        $counts = $this->sectionCounts($exam);
        $names = $exam->sections->pluck('name', 'code')->all();
        $rows = $parsed['rows'];

        $suggested = match ($parsed['kind']) {
            'fixed' => ColumnMapper::guessFixed($rows, $counts),
            default => ColumnMapper::guess($parsed['headers'], $rows, $counts, $names, $parsed['kind']),
        };
        if (count($exam->booklets ?? ['A']) === 1 && empty($suggested['booklet'])) {
            $suggested['booklet'] = ['fixed' => 'A'];
        }

        return [
            'import' => $this->serialize($import),
            'kind' => $parsed['kind'],
            'delimiter' => $parsed['delimiter'],
            'has_header' => $parsed['has_header'],
            'headers' => $parsed['headers'],
            'sample_rows' => array_slice($rows, 0, 12),
            'total_rows' => count($rows),
            'suggested_mapping' => $import->mapping ?: $suggested,
            'sections' => $exam->sections->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'question_count' => (int) $s->question_count])->values(),
            'total_questions' => array_sum($counts),
            'booklets' => $exam->booklets ?? ['A'],
            'layouts' => OpticalLayout::query()->where('format', $import->format)->orderBy('name')->get(['id', 'name', 'format', 'mapping']),
        ];
    }

    /** Eşleştirmeyi tüm satırlara uygular; eşleşen/eşleşmeyen öğrenci ve kitapçık algılama önizlemesi. */
    public function preview(Exam $exam, OpticalImport $import, array $mapping): array
    {
        $counts = $this->sectionCounts($exam);
        $errors = ColumnMapper::validate($mapping, $counts);
        if ($errors !== []) {
            throw new BusinessRuleException(implode(' ', $errors), 'mapping_incomplete', ['errors' => $errors]);
        }

        $parsed = $this->parse($import);
        $questions = $this->questionsBySection($exam);
        $booklets = $exam->booklets ?? ['A'];
        $rows = [];
        $matched = 0;
        $unmatched = [];
        $bookletCounts = array_fill_keys($booklets, 0) + ['auto' => 0, 'unknown' => 0];
        $blankRows = 0;

        foreach ($parsed['rows'] as $i => $raw) {
            $m = ColumnMapper::map($raw, $mapping, $counts);
            $student = $this->matchStudent($m['student_no'], $m['name']);
            $booklet = BookletDetector::normalize($m['booklet'], $booklets);
            $detected = false;
            if ($booklet === null && count($booklets) > 1) {
                $d = BookletDetector::detect($m['answers'], $questions, $booklets);
                $booklet = $d['booklet'];
                $detected = true;
                $bookletCounts['auto']++;
            } elseif ($booklet === null) {
                $booklet = $booklets[0];
            }
            $bookletCounts[$booklet] = ($bookletCounts[$booklet] ?? 0) + 1;
            $filled = array_sum(array_map(fn ($s) => strlen(trim($s)), $m['answers']));
            if ($filled === 0) {
                $blankRows++;
            }
            if ($student) {
                $matched++;
            } else {
                $unmatched[] = ['row' => $i + 1, 'student_no' => $m['student_no'], 'name' => $m['name']];
            }
            if (count($rows) < 40) {
                $rows[] = [
                    'row' => $i + 1, 'student_no' => $m['student_no'], 'name' => $m['name'],
                    'student' => $student ? ['id' => $student['id'], 'name' => $student['name']] : null,
                    'booklet' => $booklet, 'booklet_detected' => $detected, 'filled' => $filled,
                    'answers' => array_map(fn ($s) => rtrim($s), $m['answers']),
                ];
            }
        }

        return [
            'total' => count($parsed['rows']),
            'matched' => $matched,
            'unmatched_count' => count($unmatched),
            'unmatched' => array_slice($unmatched, 0, 200),
            'booklets' => $bookletCounts,
            'blank_rows' => $blankRows,
            'rows' => $rows,
            'will_queue' => count($parsed['rows']) > self::SYNC_LIMIT,
        ];
    }

    /** Eşleştirmeyi kaydedip içe aktarmayı başlatır: küçük dosya anında, büyük dosya kuyrukta. */
    public function run(Exam $exam, OpticalImport $import, array $mapping, ?string $saveLayoutName = null): OpticalImport
    {
        $this->assertImportable($exam);
        $counts = $this->sectionCounts($exam);
        $errors = ColumnMapper::validate($mapping, $counts);
        if ($errors !== []) {
            throw new BusinessRuleException(implode(' ', $errors), 'mapping_incomplete', ['errors' => $errors]);
        }
        if (in_array($import->status, ['processing', 'completed'], true)) {
            throw new BusinessRuleException('Bu dosya zaten işlendi. Yeni bir dosya yükleyin.', 'import_done');
        }

        if ($saveLayoutName) {
            OpticalLayout::query()->updateOrCreate(
                ['branch_id' => app(BranchContext::class)->id(), 'name' => mb_substr($saveLayoutName, 0, 80), 'format' => $import->format],
                ['mapping' => $mapping, 'created_by' => Auth::id()],
            );
        }

        $import->forceFill(['mapping' => $mapping, 'status' => 'pending', 'error' => null, 'processed_rows' => 0, 'matched_rows' => 0, 'unmatched' => null, 'report' => null])->save();

        if ($import->total_rows > self::SYNC_LIMIT) {
            ProcessOpticalImport::dispatch($import->id, (int) app(BranchContext::class)->id());
            $import->forceFill(['status' => 'queued'])->save();
        } else {
            $this->process($import);
        }

        return $import->fresh();
    }

    /** Dosyayı satır satır puanlar. Kuyruk işinden ya da senkron çağrılır. */
    public function process(OpticalImport $import): OpticalImport
    {
        $import->forceFill(['status' => 'processing', 'started_at' => now(), 'processed_rows' => 0])->save();

        try {
            $exam = Exam::query()->with('sections.questions')->findOrFail($import->exam_id);
            $parsed = $this->parse($import);
            $mapping = $import->mapping ?? [];
            $mapped = [];
            foreach ($parsed['rows'] as $i => $raw) {
                $mapped[] = ['row' => $i + 1] + ColumnMapper::map($raw, $mapping, $this->sectionCounts($exam));
            }
            $summary = $this->importMapped($exam, $mapped, 'optical', $import);

            $import->forceFill([
                'status' => 'completed', 'finished_at' => now(),
                'total_rows' => count($mapped), 'processed_rows' => count($mapped), 'matched_rows' => $summary['matched'],
                'unmatched' => array_slice($summary['unmatched'], 0, 500), 'report' => $summary['report'],
            ])->save();

            Audit::log('exam.optical_imported', sprintf('%s için optik okuma içe aktardı (%d/%d öğrenci eşleşti).', $exam->name, $summary['matched'], count($mapped)), $exam);
        } catch (\Throwable $e) {
            $import->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e instanceof BusinessRuleException ? $e->getMessage() : 'İçe aktarma sırasında beklenmeyen bir hata oluştu.', 0, 1000)])->save();
            report($e);
        }

        return $import;
    }

    /**
     * Eşleştirilmiş satırları puanlar (API ve dosya içe aktarma ortak yolu).
     *
     * @param list<array{row?:int, student_no:?string, name?:?string, booklet?:?string, answers:array<string,string>, national_rank?:?int}> $rows
     * @return array{matched:int, unmatched:list<array>, report:array}
     */
    public function importMapped(Exam $exam, array $rows, string $source = 'optical', ?OpticalImport $progress = null): array
    {
        $exam->loadMissing('sections.questions');
        $this->assertKeyReady($exam);
        $booklets = $exam->booklets ?? ['A'];
        $questions = $this->questionsBySection($exam);
        $matched = 0;
        $unmatched = [];
        $errors = [];
        $bookletCounts = array_fill_keys($booklets, 0) + ['auto' => 0];

        foreach ($rows as $i => $m) {
            $rowNo = $m['row'] ?? $i + 1;
            $student = $this->matchStudent($m['student_no'] ?? null, $m['name'] ?? null);
            if (! $student) {
                $unmatched[] = ['row' => $rowNo, 'student_no' => $m['student_no'] ?? null, 'name' => $m['name'] ?? null];
                continue;
            }
            $booklet = BookletDetector::normalize($m['booklet'] ?? null, $booklets);
            if ($booklet === null) {
                if (count($booklets) > 1) {
                    $booklet = BookletDetector::detect($m['answers'], $questions, $booklets)['booklet'];
                    $bookletCounts['auto']++;
                } else {
                    $booklet = $booklets[0];
                }
            }
            $bookletCounts[$booklet] = ($bookletCounts[$booklet] ?? 0) + 1;

            try {
                $this->results->recordAnswers($exam, Student::query()->findOrFail($student['id']), $booklet, $m['answers'], $source, $m['national_rank'] ?? null);
                $matched++;
            } catch (BusinessRuleException $e) {
                $errors[] = ['row' => $rowNo, 'student_no' => $m['student_no'] ?? null, 'message' => $e->getMessage()];
            }

            if ($progress && ($i + 1) % 25 === 0) {
                $progress->forceFill(['processed_rows' => $i + 1, 'matched_rows' => $matched])->save();
            }
        }

        // Sıralama her zaman; yayımlı sınavda soru/konu istatistikleri de tazelenir.
        $this->results->refreshAnalytics($exam);

        return ['matched' => $matched, 'unmatched' => $unmatched, 'report' => ['errors' => array_slice($errors, 0, 200), 'booklets' => $bookletCounts, 'source' => $source]];
    }

    /**
     * API ile satır gönderimi: {rows:[{student_no, booklet?, answers: {TUR:"…"} | "ABCD…"}]}
     *
     * @param list<array<string,mixed>> $rows
     */
    public function importApi(Exam $exam, array $rows): OpticalImport
    {
        $this->assertImportable($exam);
        $counts = $this->sectionCounts($exam);
        $import = OpticalImport::query()->create([
            'exam_id' => $exam->id, 'format' => 'api', 'original_name' => 'API', 'status' => 'processing',
            'total_rows' => count($rows), 'started_at' => now(), 'created_by' => Auth::id(),
        ]);

        $mapped = [];
        foreach ($rows as $i => $r) {
            $answers = $r['answers'] ?? '';
            if (is_string($answers)) {
                $answers = ColumnMapper::map([$answers], ['answers_mode' => 'combined', 'combined' => ['col' => 0]], $counts)['answers'];
            } else {
                $out = [];
                foreach ($counts as $code => $n) {
                    $out[$code] = ColumnMapper::padAnswers((string) ($answers[$code] ?? ''), $n);
                }
                $answers = $out;
            }
            $mapped[] = [
                'row' => $i + 1, 'student_no' => isset($r['student_no']) ? trim((string) $r['student_no']) : null, 'name' => $r['name'] ?? null,
                'booklet' => $r['booklet'] ?? null, 'answers' => $answers, 'national_rank' => isset($r['national_rank']) ? (int) $r['national_rank'] : null,
            ];
        }

        try {
            $summary = $this->importMapped($exam, $mapped, 'import', $import);
            $import->forceFill([
                'status' => 'completed', 'finished_at' => now(), 'processed_rows' => count($mapped), 'matched_rows' => $summary['matched'],
                'unmatched' => array_slice($summary['unmatched'], 0, 500), 'report' => $summary['report'],
            ])->save();
            Audit::log('exam.optical_imported', sprintf('%s için API ile sonuç aktardı (%d/%d öğrenci eşleşti).', $exam->name, $summary['matched'], count($mapped)), $exam);
        } catch (\Throwable $e) {
            $import->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }

        return $import;
    }

    /** Tek öğrenci için elle sonuç girişi. */
    public function manualEntry(Exam $exam, Student $student, string $booklet, array $answersBySection): void
    {
        $this->assertImportable($exam);
        $exam->loadMissing('sections.questions');
        $this->assertKeyReady($exam);
        $counts = $this->sectionCounts($exam);
        $answers = [];
        foreach ($counts as $code => $n) {
            $answers[$code] = ColumnMapper::padAnswers((string) ($answersBySection[$code] ?? ''), $n);
        }
        $this->results->recordAnswers($exam, $student, $booklet, $answers, 'manual');
        $this->results->refreshAnalytics($exam);
        Audit::log('exam.manual_result', "{$exam->name} için {$student->full_name} sonucunu elle girdi.", $exam);
    }

    public function serialize(OpticalImport $import): array
    {
        return [
            'id' => $import->id, 'exam_id' => $import->exam_id, 'format' => $import->format, 'original_name' => $import->original_name,
            'status' => $import->status, 'total_rows' => $import->total_rows, 'matched_rows' => $import->matched_rows, 'processed_rows' => $import->processed_rows,
            'unmatched' => $import->unmatched ?? [], 'unmatched_count' => count($import->unmatched ?? []), 'report' => $import->report,
            'error' => $import->error, 'started_at' => $import->started_at?->toIso8601String(), 'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(), 'created_by' => $import->relationLoaded('creator') ? $import->creator?->name : null,
            'progress' => $import->total_rows > 0 ? (int) round(min(100, $import->processed_rows / $import->total_rows * 100)) : 0,
        ];
    }

    // ------------------------------------------------------------------ yardımcılar

    /** @return array{kind:string, delimiter:?string, has_header:bool, headers:list<string>, rows:list<mixed>} */
    private function parse(OpticalImport $import): array
    {
        $disk = Storage::disk(self::DISK);
        if (! $import->path || ! $disk->exists($import->path)) {
            throw new BusinessRuleException('Yüklenen dosya bulunamadı. Lütfen tekrar yükleyin.', 'optical_file_missing');
        }
        $content = $import->format === 'xlsx' ? '' : (string) $disk->get($import->path);

        return OpticalParser::parse($import->format, $content, $disk->path($import->path));
    }

    /** @return array<string,int> */
    private function sectionCounts(Exam $exam): array
    {
        $exam->loadMissing('sections');

        return $exam->sections->pluck('question_count', 'code')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, list<array{number:int, booklet_map:array, is_cancelled:bool}>> */
    private function questionsBySection(Exam $exam): array
    {
        $exam->loadMissing('sections.questions');
        $out = [];
        foreach ($exam->sections as $s) {
            $out[$s->code] = $s->questions->map(fn (ExamQuestion $q) => ['number' => $q->number, 'booklet_map' => $q->booklet_map, 'is_cancelled' => $q->is_cancelled])->all();
        }

        return $out;
    }

    /** @return array{id:int,name:string}|null */
    private function matchStudent(?string $no, ?string $name): ?array
    {
        if ($this->studentIndex === null) {
            $this->studentIndex = [];
            $this->nameIndex = [];
            Student::query()->whereNotIn('status', ['lead', 'interview', 'offer'])->get(['id', 'student_no', 'full_name'])->each(function ($s) {
                $this->studentIndex[self::normalizeNo($s->student_no)] = ['id' => $s->id, 'name' => $s->full_name];
                $this->nameIndex[ColumnMapper::norm($s->full_name)] = ['id' => $s->id, 'name' => $s->full_name];
            });
        }
        if ($no !== null && $no !== '') {
            $key = self::normalizeNo($no);
            if (isset($this->studentIndex[$key])) {
                return $this->studentIndex[$key];
            }
        }
        if ($name !== null && $name !== '') {
            $key = ColumnMapper::norm($name);
            if (isset($this->nameIndex[$key])) {
                return $this->nameIndex[$key];
            }
        }

        return null;
    }

    public static function normalizeNo(?string $no): string
    {
        $no = trim((string) $no);
        if (ctype_digit($no)) {
            return ltrim($no, '0') ?: '0';
        }

        return mb_strtoupper($no);
    }

    private function assertImportable(Exam $exam): void
    {
        if ($exam->status === 'draft') {
            throw new BusinessRuleException('Önce cevap anahtarını eksiksiz girin; sonuçlar anahtar olmadan puanlanamaz.', 'answer_key_missing');
        }
    }

    private function assertKeyReady(Exam $exam): void
    {
        foreach ($exam->sections as $s) {
            if ($s->questions->count() < (int) $s->question_count) {
                throw new BusinessRuleException("{$s->name} bölümünün cevap anahtarı eksik ({$s->questions->count()}/{$s->question_count}).", 'answer_key_missing');
            }
        }
    }
}
