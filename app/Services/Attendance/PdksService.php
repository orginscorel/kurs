<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\DeviceIdentity;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PDKS — kurumun KENDİ personel/öğrenci devam kontrol sistemi (Perkotek yazılımı gerekmez).
 *
 * Veri kaynağı yeni bir yapı DEĞİL: device_identities (cihaz kullanıcı no ↔ öğrenci/öğretmen/çalışan) ve
 * attendance_events (her okutma). Öğrenci okutması yoklamaya (PresenceService), personel okutması personel
 * giriş-çıkışına yazılır. Günlük/aylık özet olaylardan hesaplanır (ayrı tablo tutulmaz → tek doğru kaynak).
 */
class PdksService
{
    public const PERSON_TYPES = ['student' => 'Öğrenci', 'teacher' => 'Öğretmen', 'employee' => 'Çalışan'];

    /** Terminal kişileri: eşlenmiş kimlikler + eşleşmemiş (bekleyen) cihaz kullanıcı numaraları. */
    public function people(int $branchId, ?string $q = null): array
    {
        $identities = DeviceIdentity::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->whereIn('kind', ['fingerprint', 'card'])
            ->orderBy('identifier')->get();

        $names = $this->names($identities->map(fn ($i) => [$i->person_type, $i->person_id]));

        $lastSeen = DB::table('attendance_events')->where('branch_id', $branchId)->whereNotNull('raw_identifier')
            ->selectRaw('raw_identifier, MAX(occurred_at) AS son, SUM(CASE WHEN is_matched = 0 THEN 1 ELSE 0 END) AS eslesmeyen')
            ->groupBy('raw_identifier')->get()->keyBy('raw_identifier');

        $rows = $identities->map(function (DeviceIdentity $i) use ($names, $lastSeen) {
            $person = $names[$i->person_type.':'.$i->person_id] ?? null;

            return [
                'id' => $i->id,
                'kullanici_no' => $i->identifier,
                'tur' => $i->kind,
                'kisi_turu' => $i->person_type,
                'kisi_turu_etiketi' => self::PERSON_TYPES[$i->person_type] ?? $i->person_type,
                'kisi_id' => $i->person_id,
                'kisi' => $person['ad'] ?? '(silinmiş kişi)',
                'kisi_no' => $person['no'] ?? null,
                'aktif' => (bool) $i->is_active,
                'son_okutma' => $lastSeen[$i->identifier]->son ?? null,
                'eslesmeyen_okutma' => (int) ($lastSeen[$i->identifier]->eslesmeyen ?? 0),
            ];
        })->values();

        // Terminaldeki kullanıcı adları (YT33 push kaydı; yalnız cihazı dinleyen Mac'te dolu)
        $deviceNames = Schema::hasTable('terminal_device_users')
            ? DB::table('terminal_device_users')->where('branch_id', $branchId)->orderBy('updated_at')->get(['user_no', 'name', 'link_status'])->keyBy('user_no')
            : collect();

        $mapped = $identities->pluck('identifier')->all();
        $pending = $lastSeen->filter(fn ($r, $id) => ! in_array((string) $id, $mapped, true) && (int) $r->eslesmeyen > 0)
            ->map(fn ($r, $id) => ['kullanici_no' => (string) $id, 'son_okutma' => $r->son, 'eslesmeyen_okutma' => (int) $r->eslesmeyen])
            ->values();

        // Cihaza kaydedilmiş ama henüz okutma yapmamış (ve eşlenmemiş) kullanıcılar da bekleyendir
        $seen = $pending->pluck('kullanici_no')->all();
        foreach ($deviceNames as $no => $u) {
            if (! in_array((string) $no, $mapped, true) && ! in_array((string) $no, $seen, true)) {
                $pending->push(['kullanici_no' => (string) $no, 'son_okutma' => null, 'eslesmeyen_okutma' => 0]);
            }
        }
        $pending = $pending->map(fn ($r) => $r + [
            'cihazdaki_ad' => $deviceNames[$r['kullanici_no']]->name ?? null,
            'oto_eslesme' => $deviceNames[$r['kullanici_no']]->link_status ?? null,
        ])->values();
        $rows = $rows->map(fn ($r) => $r + ['cihazdaki_ad' => $deviceNames[$r['kullanici_no']]->name ?? null])->values();

        if ($q = trim((string) $q)) {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['kisi'].' '.$r['kullanici_no'].' '.$r['kisi_no'].' '.$r['cihazdaki_ad']), $needle))->values();
            $pending = $pending->filter(fn ($r) => str_contains(mb_strtolower($r['kullanici_no'].' '.$r['cihazdaki_ad']), $needle))->values();
        }

        return ['kisiler' => $rows->all(), 'bekleyen' => $pending->all()];
    }

    /**
     * Cihaz kullanıcı numarasını bir kişiye bağlar. Numara başka birine bağlıysa $confirm olmadan DEĞİŞTİRMEZ
     * (yanlış eşleştirme yanlış kişiye giriş-çıkış yazar).
     */
    public function link(int $branchId, string $identifier, string $personType, int $personId, bool $confirm = false, string $kind = 'fingerprint', bool $rematch = true): array
    {
        $person = $this->findPerson($branchId, $personType, $personId);

        $existing = DeviceIdentity::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('kind', $kind)->where('identifier', $identifier)->first();

        if ($existing && ($existing->person_type !== $personType || (int) $existing->person_id !== $personId) && ! $confirm) {
            $current = $this->names(collect([[$existing->person_type, $existing->person_id]]))[$existing->person_type.':'.$existing->person_id]['ad'] ?? '?';
            throw new BusinessRuleException(
                "{$identifier} numaralı cihaz kullanıcısı zaten \"{$current}\" kişisine bağlı. Değiştirmek için onaylayın.",
                'pdks_identity_conflict', ['kullanici_no' => $identifier, 'mevcut' => $current], 409,
            );
        }

        $identity = DeviceIdentity::query()->withoutGlobalScope('branch')->updateOrCreate(
            ['branch_id' => $branchId, 'kind' => $kind, 'identifier' => $identifier],
            ['person_type' => $personType, 'person_id' => $personId, 'is_active' => true],
        );

        $rematched = $rematch ? $this->rematch($branchId, $identifier, $personType, $personId) : 0;

        Audit::log('device_identity.upserted', "{$person['ad']} (".self::PERSON_TYPES[$personType].") kişisini {$identifier} numaralı cihaz kullanıcısına bağladı.");

        return ['id' => $identity->id, 'kisi' => $person['ad'], 'baglanan_eski_okutma' => $rematched];
    }

    /**
     * Eşleşmemiş (bekleyen) eski okutmaları yeni eşlemeye bağlar. Öğrenci için olay yalnız işaretlenir;
     * yoklama/daily_presences yeniden hesaplanmaz (geçmiş gün yoklaması değişmesin — bilinçli).
     */
    private function rematch(int $branchId, string $identifier, string $personType, int $personId): int
    {
        return DB::table('attendance_events')->where('branch_id', $branchId)->where('raw_identifier', $identifier)->where('is_matched', false)
            ->update([
                'is_matched' => true,
                'person_type' => $personType,
                'person_id' => $personId,
                'student_id' => $personType === 'student' ? $personId : null,
            ]);
    }

    /**
     * CSV ön izleme: "cihaz no;öğrenci no" (ayraç ; ya da ,; başlık satırı olabilir). Hiçbir şey YAZMAZ.
     *
     * @return list<array{satir:int, kullanici_no:string, ogrenci_no:string, ogrenci_id:?int, ogrenci:?string, durum:string, mesaj:string}>
     */
    public function previewCsv(int $branchId, string $content): array
    {
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];

        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = array_map('trim', str_getcsv($line, str_contains($line, ';') ? ';' : ','));
            [$identifier, $studentNo] = array_pad($cols, 2, '');

            if ($n === 0 && ! ctype_digit(ltrim($identifier, '0') ?: '0')) {
                continue;   // başlık satırı
            }

            $row = ['satir' => $n + 1, 'kullanici_no' => $identifier, 'ogrenci_no' => $studentNo, 'ogrenci_id' => null, 'ogrenci' => null, 'durum' => 'hata', 'mesaj' => ''];

            if ($identifier === '' || $studentNo === '') {
                $rows[] = ['mesaj' => 'Eksik sütun (cihaz no;öğrenci no).'] + $row;

                continue;
            }

            $student = Student::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('student_no', $studentNo)->first(['id', 'full_name']);
            if (! $student) {
                $rows[] = ['mesaj' => "{$studentNo} numaralı öğrenci bulunamadı."] + $row;

                continue;
            }

            $row['ogrenci_id'] = $student->id;
            $row['ogrenci'] = $student->full_name;
            $existing = DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
                ->where('kind', 'fingerprint')->where('identifier', $identifier)->first();

            if ($existing && ($existing->person_type !== 'student' || (int) $existing->person_id !== $student->id)) {
                $row['durum'] = 'cakisma';
                $row['mesaj'] = 'Bu cihaz numarası başka bir kişiye bağlı; onaylarsanız değiştirilir.';
            } elseif ($existing) {
                $row['durum'] = 'ayni';
                $row['mesaj'] = 'Zaten bu öğrenciye bağlı.';
            } else {
                $row['durum'] = 'yeni';
                $row['mesaj'] = 'Eşlenecek.';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** Ön izlemesi onaylanan CSV'yi uygular; çakışanlar yalnız $overwrite ile değişir. */
    public function applyCsv(int $branchId, string $content, bool $overwrite): array
    {
        $done = 0;
        $skipped = 0;

        DB::transaction(function () use ($branchId, $content, $overwrite, &$done, &$skipped) {
            foreach ($this->previewCsv($branchId, $content) as $row) {
                if ($row['durum'] === 'yeni' || ($row['durum'] === 'cakisma' && $overwrite)) {
                    $this->link($branchId, $row['kullanici_no'], 'student', (int) $row['ogrenci_id'], true);
                    $done++;
                } else {
                    $skipped++;
                }
            }
        });

        return ['eslenen' => $done, 'atlanan' => $skipped];
    }

    /** Giriş-çıkış kayıtları sorgusu (öğrenci / personel / eşleşmemiş). */
    public function eventsQuery(int $branchId, array $f)
    {
        $from = CarbonImmutable::parse($f['baslangic'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($f['bitis'] ?? now()->toDateString())->endOfDay();

        $q = DB::table('attendance_events')->where('branch_id', $branchId)
            ->whereBetween('occurred_at', [$from, $to])->where('event_type', '!=', 'IGNORED');

        match ($f['kisi_turu'] ?? 'hepsi') {
            'ogrenci' => $q->where('person_type', 'student'),
            'personel' => $q->whereIn('person_type', ['teacher', 'employee']),
            'eslesmeyen' => $q->where('is_matched', false),
            default => null,
        };

        if (! empty($f['yon']) && in_array($f['yon'], ['ENTRY', 'EXIT'], true)) {
            $q->where('event_type', $f['yon']);
        }
        if (! empty($f['kullanici_no'])) {
            $q->where('raw_identifier', $f['kullanici_no']);
        }
        if (! empty($f['kisi_tipi']) && ! empty($f['kisi_id'])) {
            $q->where('person_type', $f['kisi_tipi'])->where('person_id', (int) $f['kisi_id']);
        }
        if (! empty($f['cihaz_id'])) {
            $q->where('device_id', (int) $f['cihaz_id']);
        }

        return $q->orderByDesc('occurred_at');
    }

    /** Olay satırlarını ekrana/Excel'e hazırlar (kişi adı, cihaz adı). */
    public function decorate(Collection $events, int $branchId): array
    {
        $names = $this->names($events->filter(fn ($e) => $e->person_type)->map(fn ($e) => [$e->person_type, $e->person_id]));
        $devices = DB::table('devices')->where('branch_id', $branchId)->pluck('name', 'id');

        return $events->map(fn ($e) => [
            'id' => $e->id,
            'zaman' => $e->occurred_at,
            'yon' => $e->event_type,
            'yontem' => $e->source,
            'kullanici_no' => $e->raw_identifier,
            'eslesti' => (bool) $e->is_matched,
            'kisi_turu' => $e->person_type,
            'kisi_turu_etiketi' => self::PERSON_TYPES[$e->person_type] ?? null,
            'kisi' => $e->person_type ? ($names[$e->person_type.':'.$e->person_id]['ad'] ?? null) : null,
            'kisi_no' => $e->person_type ? ($names[$e->person_type.':'.$e->person_id]['no'] ?? null) : null,
            'cihaz' => $e->device_id ? ($devices[$e->device_id] ?? null) : null,
        ])->values()->all();
    }

    /**
     * Günlük özet: kişi × gün → ilk giriş, son çıkış, içeride geçen süre (giriş-çıkış çiftleri), geç kalma.
     * Personel için "geç" = ilk giriş > mesai başlangıcı + tolerans.
     */
    public function dailySummary(int $branchId, array $f): array
    {
        $f['kisi_turu'] = ($f['kisi_turu'] ?? 'personel') === 'eslesmeyen' ? 'personel' : ($f['kisi_turu'] ?? 'personel');
        $events = $this->eventsQuery($branchId, $f)->whereNotNull('person_type')->whereIn('event_type', ['ENTRY', 'EXIT'])
            ->reorder('occurred_at')->limit(50000)->get(['person_type', 'person_id', 'event_type', 'occurred_at']);

        $start = (string) ($f['mesai_baslangic'] ?? '09:00');
        $grace = (int) ($f['tolerans_dk'] ?? 5);
        $names = $this->names($events->map(fn ($e) => [$e->person_type, $e->person_id]));
        $days = [];

        foreach ($events as $e) {
            $at = CarbonImmutable::parse($e->occurred_at);
            $key = $e->person_type.':'.$e->person_id.':'.$at->toDateString();
            $d = $days[$key] ??= ['kisi_turu' => $e->person_type, 'kisi_id' => $e->person_id, 'tarih' => $at->toDateString(), 'ilk_giris' => null, 'son_cikis' => null, 'dakika' => 0, '_in' => null, 'okutma' => 0];
            $d['okutma']++;

            if ($e->event_type === 'ENTRY') {
                $d['ilk_giris'] ??= $at->format('H:i');
                $d['_in'] ??= $at;
            } else {
                $d['son_cikis'] = $at->format('H:i');
                if ($d['_in']) {
                    $d['dakika'] += (int) $d['_in']->diffInMinutes($at);
                    $d['_in'] = null;
                }
            }
            $days[$key] = $d;
        }

        $rows = [];
        foreach ($days as $d) {
            $late = $d['kisi_turu'] !== 'student' && $d['ilk_giris'] !== null
                && CarbonImmutable::parse($d['tarih'].' '.$d['ilk_giris'])->gt(CarbonImmutable::parse($d['tarih'].' '.$start)->addMinutes($grace));
            unset($d['_in']);
            $rows[] = $d + [
                'kisi' => $names[$d['kisi_turu'].':'.$d['kisi_id']]['ad'] ?? '?',
                'kisi_turu_etiketi' => self::PERSON_TYPES[$d['kisi_turu']] ?? $d['kisi_turu'],
                'sure' => sprintf('%d sa %02d dk', intdiv($d['dakika'], 60), $d['dakika'] % 60),
                'gec' => $late,
                'cikis_eksik' => $d['son_cikis'] === null,
            ];
        }

        usort($rows, fn ($a, $b) => [$b['tarih'], $a['kisi']] <=> [$a['tarih'], $b['kisi']]);

        // Aylık (seçili aralık) toplam
        $totals = [];
        foreach ($rows as $r) {
            $k = $r['kisi_turu'].':'.$r['kisi_id'];
            $t = $totals[$k] ??= ['kisi' => $r['kisi'], 'kisi_turu_etiketi' => $r['kisi_turu_etiketi'], 'gun' => 0, 'dakika' => 0, 'gec' => 0, 'cikis_eksik' => 0];
            $t['gun']++;
            $t['dakika'] += $r['dakika'];
            $t['gec'] += $r['gec'] ? 1 : 0;
            $t['cikis_eksik'] += $r['cikis_eksik'] ? 1 : 0;
            $totals[$k] = $t;
        }
        $totals = array_values(array_map(fn ($t) => $t + ['sure' => sprintf('%d sa %02d dk', intdiv($t['dakika'], 60), $t['dakika'] % 60)], $totals));
        usort($totals, fn ($a, $b) => $a['kisi'] <=> $b['kisi']);

        return ['gunluk' => $rows, 'toplam' => $totals, 'mesai_baslangic' => $start, 'tolerans_dk' => $grace];
    }

    /** Kişi arama (eşleştirme seçicisi): öğrenci + öğretmen + çalışan. */
    public function searchPeople(int $branchId, string $q, ?string $type = null): array
    {
        $q = trim($q);
        $out = [];
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        if (! $type || $type === 'student') {
            foreach (Student::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
                ->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('student_no', 'like', $like))
                ->limit(15)->get(['id', 'full_name', 'student_no']) as $s) {
                $out[] = ['kisi_turu' => 'student', 'kisi_id' => $s->id, 'ad' => $s->full_name, 'no' => $s->student_no, 'etiket' => 'Öğrenci'];
            }
        }

        foreach (['teacher' => Teacher::class, 'employee' => Employee::class] as $t => $model) {
            if ($type && $type !== $t) {
                continue;
            }
            foreach ($model::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
                ->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhereRaw($this->fullNameSql().' like ?', [$like]))
                ->limit(10)->get() as $p) {
                $out[] = ['kisi_turu' => $t, 'kisi_id' => $p->id, 'ad' => trim($p->first_name.' '.$p->last_name), 'no' => null, 'etiket' => self::PERSON_TYPES[$t]];
            }
        }

        return $out;
    }

    private function fullNameSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "(first_name || ' ' || last_name)" : "CONCAT(first_name, ' ', last_name)";
    }

    /** @return array{ad:string, no:?string} */
    private function findPerson(int $branchId, string $type, int $id): array
    {
        $person = $this->names(collect([[$type, $id]]), $branchId)[$type.':'.$id] ?? null;

        if (! $person) {
            throw new BusinessRuleException('Kişi bu şubede bulunamadı.', 'person_not_found', [], 404);
        }

        return $person;
    }

    /** @return array<string, array{ad:string, no:?string}> "tür:id" → ad/no */
    private function names(Collection $pairs, ?int $branchId = null): array
    {
        $out = [];
        $byType = $pairs->groupBy(fn ($p) => $p[0])->map(fn ($g) => $g->pluck(1)->unique()->values()->all());

        foreach ($byType as $type => $ids) {
            $query = match ($type) {
                'student' => Student::query()->withoutGlobalScope('branch')->select(['id', 'full_name', 'student_no', 'branch_id']),
                'teacher' => Teacher::query()->withoutGlobalScope('branch')->select(['id', 'first_name', 'last_name', 'branch_id']),
                'employee' => Employee::query()->withoutGlobalScope('branch')->select(['id', 'first_name', 'last_name', 'branch_id']),
                default => null,
            };
            if (! $query) {
                continue;
            }
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
            foreach ($query->whereIn('id', $ids)->get() as $m) {
                $out[$type.':'.$m->id] = $type === 'student'
                    ? ['ad' => $m->full_name, 'no' => $m->student_no]
                    : ['ad' => trim($m->first_name.' '.$m->last_name), 'no' => null];
            }
        }

        return $out;
    }
}
