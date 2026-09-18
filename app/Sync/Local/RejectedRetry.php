<?php

namespace App\Sync\Local;

use App\Sync\Commands\CommandRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sunucunun REDDETTİĞİ yerel değişiklikleri yeniden deneme planı (yerel düğüm).
 *
 * Neden? Ret çoğu zaman geçicidir: üst kayıt (ör. ön kayıt) henüz sunucuda yoktu ('missing_reference'),
 * sunucu o an bir kuralı farklı değerlendirdi… Eskiden satır sonsuza dek "reddedildi" kalıyordu.
 *
 * Kurallar:
 *  - Otomatik deneme YALNIZ satır değişikliklerinde. KOMUTLAR (tahsilat, iade, kayıt… finans) otomatik
 *    yeniden yürütülmez: ret anında yerelde geri alınmıştır; kullanıcı işlemi elle yeniden girmiş olabilir
 *    (çift tahsilat riski). Komutlar yalnız kullanıcı "Yeniden dene" derse gönderilir.
 *  - Ne zaman: uygulama açılışında/uyanmada (reset-locks sonrası ilk tur), sonra satır başına geri çekilmeyle
 *    6 sa → 12 sa → 24 sa …; en fazla MAX_AUTO otomatik deneme (sonra yalnız elle). Sonsuz döngü yok.
 *  - Zincir: aynı turda bir değişiklik kabul edilirse, 'missing_reference' ile reddedilmiş olanlar beklemeden
 *    yeniden denenir (üst kayıt artık sunucuda); tur içinde en fazla MAX_PASSES geçiş, yalnız ilerleme varken.
 *  - Sunucu 'accepted' ya da 'duplicate(previous=accepted)' derse satır tamamlanır ('pushed').
 *
 * Durum sync_state'te JSON (şema değişikliği yok): {sync_changes.id: {n: deneme, next: unix, code: ret kodu}}.
 */
class RejectedRetry
{
    public const MAX_AUTO = 6;

    public const MAX_PASSES = 5;

    public const BASE_SECONDS = 6 * 3600;

    private const META = 'rejected_retry';

    private const ON_START = 'rejected_retry_on_start';

    private const MANUAL = 'rejected_retry_manual';

    public function __construct(private readonly LocalState $state) {}

    /** @return array<int, array{n: int, next: int, code: ?string}> */
    public function meta(): array
    {
        $raw = json_decode((string) $this->state->get(self::META, '{}'), true);

        return is_array($raw) ? array_map(fn ($m) => ['n' => (int) ($m['n'] ?? 0), 'next' => (int) ($m['next'] ?? 0), 'code' => $m['code'] ?? null], $raw) : [];
    }

    private function save(array $meta): void
    {
        $this->state->put(self::META, $meta ? json_encode($meta) : null);
    }

    /** Ret kaydı: ilk ret (attempt=false) sayacı başlatır; yeniden denemede ret sayacı artırır ve geri çekilir. */
    public function noteRejected(int $changeId, ?string $code, bool $attempt): void
    {
        $meta = $this->meta();
        $m = $meta[$changeId] ?? ['n' => 0, 'next' => 0, 'code' => null];
        if ($attempt) {
            $m['n']++;
        }
        $m['code'] = $code ?: $m['code'];
        $m['next'] = time() + self::BASE_SECONDS * (2 ** min(max($m['n'] - 1, 0), 2));
        $meta[$changeId] = $m;
        $this->save($meta);
    }

    /** @param list<int> $ids */
    public function forget(array $ids): void
    {
        $meta = $this->meta();
        foreach ($ids as $id) {
            unset($meta[(int) $id]);
        }
        $this->save($meta);
    }

    public function markStartup(): void
    {
        $this->state->put(self::ON_START, '1');
    }

    /** @param list<int>|null $ids null = tümü */
    public function requestManual(?array $ids): void
    {
        $cur = $this->state->get(self::MANUAL);
        if ($ids === null || $cur === '*') {
            $this->state->put(self::MANUAL, '*');
        } else {
            $prev = $cur ? (array) json_decode($cur, true) : [];
            $this->state->put(self::MANUAL, json_encode(array_values(array_unique(array_map('intval', array_merge($prev, $ids))))));
        }
        $this->state->requestSync();
    }

    /** @return array{manual: list<int>|string|null, startup: bool} bekleyen tetikleyiciler (okununca temizlenir) */
    public function takeTriggers(): array
    {
        $manual = $this->state->get(self::MANUAL);
        $startup = (bool) $this->state->get(self::ON_START);
        $this->state->put(self::MANUAL, null);
        $this->state->put(self::ON_START, null);

        return ['manual' => $manual === '*' ? '*' : ($manual ? array_map('intval', (array) json_decode($manual, true)) : null), 'startup' => $startup];
    }

    /**
     * Bu geçişte gönderilecek reddedilmiş değişiklikler (id sırasıyla: üst kayıt alttan önce).
     *
     * @param  list<int>|string|null  $manual
     * @param  array<int, true>  $tried  bu turda denenmiş olanlar (zincir geçişlerinde yalnız missing_reference tekrar)
     */
    public function due(array|string|null $manual, bool $startup, bool $progress, array $tried): Collection
    {
        $meta = $this->meta();
        $now = time();

        return DB::table('sync_changes')->where('status', 'rejected')->orderBy('id')->limit(500)->get()
            ->filter(function ($r) use ($meta, $now, $manual, $startup, $progress, $tried) {
                $m = $meta[(int) $r->id] ?? ['n' => 0, 'next' => 0, 'code' => null];
                if (isset($tried[(int) $r->id])) {
                    return $progress && $m['code'] === 'missing_reference' && $r->op !== 'command';
                }
                if ($manual === '*' || (is_array($manual) && in_array((int) $r->id, $manual, true))) {
                    return true;
                }
                if ($r->op === 'command' || $m['n'] >= self::MAX_AUTO) {
                    return false;
                }

                return $startup || $m['next'] <= $now || ($progress && $m['code'] === 'missing_reference');
            })->values();
    }

    /**
     * "N reddedilen" listesi (üst çubuk).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $meta = $this->meta();

        return DB::table('sync_changes')->where('status', 'rejected')->orderByDesc('id')->limit(200)->get()
            ->map(function ($r) use ($meta) {
                $m = $meta[(int) $r->id] ?? ['n' => 0, 'next' => 0, 'code' => null];
                $fields = $r->fields === null ? [] : (array) json_decode((string) $r->fields, true);
                $isCommand = $r->op === 'command';

                return [
                    'id' => (int) $r->id,
                    'kind' => $isCommand ? 'command' : 'row',
                    'table' => $r->table_name,
                    'table_label' => $isCommand ? CommandRegistry::label((string) ($fields['name'] ?? $r->table_name)) : (self::TABLE_LABELS[$r->table_name] ?? $r->table_name),
                    'op_label' => self::OP_LABELS[$r->op] ?? $r->op,
                    'label' => $isCommand ? null : $this->rowLabel((string) $r->table_name, (string) $r->row_uuid, $fields),
                    'reason' => $r->error,
                    'code' => $m['code'],
                    'attempts' => $m['n'],
                    'auto' => ! $isCommand && $m['n'] < self::MAX_AUTO,
                    'next_auto_at' => ! $isCommand && $m['n'] < self::MAX_AUTO && $m['next'] ? date(DATE_ATOM, $m['next']) : null,
                    'created_at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
                ];
            })->all();
    }

    private function rowLabel(string $table, string $uuid, array $fields): ?string
    {
        $row = null;
        try {
            $row = Schema::hasColumn($table, 'uuid') ? DB::table($table)->where('uuid', $uuid)->first() : null;
        } catch (\Throwable) {
        }
        $r = $row ? (array) $row : $fields;
        $name = trim(($r['full_name'] ?? '') ?: trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')));
        $label = $name ?: ($r['name'] ?? $r['title'] ?? $r['subject'] ?? null);

        return $label ? mb_substr((string) $label, 0, 120) : null;
    }

    private const OP_LABELS = ['insert' => 'Yeni kayıt', 'update' => 'Güncelleme', 'delete' => 'Silme', 'upsert' => 'Kayıt', 'command' => 'İşlem'];

    private const TABLE_LABELS = [
        'students' => 'Öğrenci', 'guardians' => 'Veli', 'teachers' => 'Öğretmen', 'attendances' => 'Yoklama', 'leads' => 'Ön kayıt',
        'lead_activities' => 'Ön kayıt notu', 'guidance_meetings' => 'Rehberlik görüşmesi', 'class_groups' => 'Sınıf', 'lesson_sessions' => 'Ders',
        'homework' => 'Ödev', 'discipline_incidents' => 'Disiplin olayı', 'documents' => 'Belge', 'student_notes' => 'Öğrenci notu', 'tags' => 'Etiket',
        'daily_presences' => 'Günlük giriş', 'attendance_events' => 'Giriş/çıkış olayı', 'tasks' => 'Görev', 'guardian_student' => 'Veli bağlantısı',
        'class_group_student' => 'Sınıf öğrencisi', 'audit_logs' => 'Denetim kaydı', 'student_observations' => 'Gözlem', 'contact_requests' => 'İletişim talebi',
    ];
}
