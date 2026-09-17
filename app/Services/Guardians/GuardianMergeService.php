<?php

namespace App\Services\Guardians;

use App\Exceptions\BusinessRuleException;
use App\Models\Guardian;
use App\Models\User;
use App\Services\Portal\PortalAccounts;
use App\Support\Audit;
use App\Support\Sensitive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mükerrer veli birleştirme. "Kaynak" veli "hedef" veliye katılır:
 *  - öğrenci bağları (aynı öğrenciye iki bağ varsa işaretler birleşir),
 *  - finansal sorumluluk (kayıt ödeme sorumlusu, tahsilat, senet, fatura alıcısı, yevmiye cari, tahsilat takibi),
 *  - mesaj geçmişi (giden mesajlar, toplu gönderim alıcıları, ret listesi, öğretmen talepleri),
 *  - ileti onayları, etiketler, görevler/belgeler, portal hesabı taşınır.
 * Belge üzerindeki ad/unvan anlık görüntüleri (fatura alıcı adı, senet borçlu adı) DEĞİŞMEZ; yalnız referans taşınır.
 * Tek transaction; kaynak veli SİLİNMEZ, soft delete edilir; denetim kaydına ayrıntı yazılır.
 */
class GuardianMergeService
{
    /** Doğrudan veli kimliği tutan sütunlar: tablo => [sütun, etiket, finansal mı] */
    public const FOREIGN_KEYS = [
        'enrollments' => ['financial_guardian_id', 'Kayıt ödeme sorumlusu', true],
        'payments' => ['guardian_id', 'Tahsilat (ödeyen)', true],
        'promissory_notes' => ['guardian_id', 'Senet borçlusu', true],
        'collection_notes' => ['guardian_id', 'Tahsilat takip notu', true],
        'contact_requests' => ['guardian_id', 'Öğretmen talebi', false],
    ];

    /** Çok biçimli (tür + kimlik) referanslar: tablo => [tür sütunu, kimlik sütunu, etiket, finansal mı] */
    public const MORPHS = [
        'invoices' => ['buyer_type', 'buyer_id', 'Fatura alıcısı', true],
        'journal_lines' => ['partner_type', 'partner_id', 'Yevmiye cari satırı', true],
        'outbound_messages' => ['recipient_type', 'recipient_id', 'Giden mesaj', false],
        'message_campaign_recipients' => ['recipient_type', 'recipient_id', 'Toplu gönderim alıcısı', false],
        'communication_suppressions' => ['recipient_type', 'recipient_id', 'Ret listesi kaydı', false],
        'tasks' => ['taskable_type', 'taskable_id', 'Görev', false],
        'documents' => ['documentable_type', 'documentable_id', 'Belge', false],
        'activity_feed' => ['subject_type', 'subject_id', 'Etkinlik akışı', false],
    ];

    /** Hedefte boşsa kaynaktan doldurulan alanlar */
    public const FILLABLE = ['whatsapp_phone' => 'WhatsApp numarası', 'email' => 'E-posta', 'occupation' => 'Meslek', 'address' => 'Adres'];

    /** @var array<string, bool> */
    private array $schemaCache = [];

    public function __construct(private readonly GuardianAccountService $accounts) {}

    /** Telefonun karşılaştırma anahtarı: son 10 hane ("0532 111 22 33" = "905321112233"). */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /**
     * Aynı telefonlu (telefon ya da WhatsApp numarası) veli grupları.
     *
     * @return list<array{key:string, guardian_ids:list<int>}>
     */
    public function duplicateGroups(): array
    {
        $rows = Guardian::query()->orderBy('id')->get(['id', 'phone', 'whatsapp_phone']);

        // Birleşim-bul: aynı anahtarı paylaşan veliler tek grupta (telefonu biri, WhatsApp'ı diğeri olsa da)
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            return ($parent[$x] ?? $x) === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $byKey = [];
        foreach ($rows as $g) {
            $parent[$g->id] ??= $g->id;
            foreach (array_unique(array_filter([self::phoneKey($g->phone), self::phoneKey($g->whatsapp_phone)])) as $key) {
                if (isset($byKey[$key])) {
                    $parent[$find($g->id)] = $find($byKey[$key]);
                } else {
                    $byKey[$key] = $g->id;
                }
            }
        }

        $groups = [];
        foreach ($rows as $g) {
            $groups[$find($g->id)][] = $g->id;
        }
        $out = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $first = $rows->firstWhere('id', $ids[0]);
            $out[] = ['key' => (string) (self::phoneKey($first->phone) ?? self::phoneKey($first->whatsapp_phone)), 'guardian_ids' => array_values($ids)];
        }

        return $out;
    }

    /** @return array<int, list<int>> veli id => aynı telefonlu diğer veli id'leri */
    public function duplicateMap(): array
    {
        $map = [];
        foreach ($this->duplicateGroups() as $group) {
            foreach ($group['guardian_ids'] as $id) {
                $map[$id] = array_values(array_diff($group['guardian_ids'], [$id]));
            }
        }

        return $map;
    }

    /** Birleştirme önizlemesi (hiçbir şey yazmaz). */
    public function preview(int $targetId, int $sourceId): array
    {
        [$target, $source] = $this->pair($targetId, $sourceId);

        $plan = $this->plan($target, $source);
        $plan['fingerprint'] = $this->fingerprint($plan);

        return $plan;
    }

    /**
     * Birleştirir. $fingerprint verilirse önizlemeden bu yana veri değiştiyse işlem durur (409).
     *
     * @return array{target_id:int, source_id:int, moved:array<string,int>, message:string}
     */
    public function merge(int $targetId, int $sourceId, ?string $fingerprint = null): array
    {
        return DB::transaction(function () use ($targetId, $sourceId, $fingerprint) {
            // Kilit: aynı anda iki birleştirme aynı veliye dokunamasın
            Guardian::query()->whereIn('id', [$targetId, $sourceId])->lockForUpdate()->get(['id']);
            [$target, $source] = $this->pair($targetId, $sourceId);

            $plan = $this->plan($target, $source);
            if ($plan['blocking']) {
                throw new BusinessRuleException('TC kimlik numaraları farklı olan veliler birleştirilemez.', 'merge_national_id_mismatch', [], 422);
            }
            if ($fingerprint !== null && ! hash_equals($this->fingerprint($plan), $fingerprint)) {
                throw new BusinessRuleException('Önizlemeden sonra velilerin kayıtları değişti. Lütfen önizlemeyi yenileyip yeniden onaylayın.', 'merge_preview_stale', [], 409);
            }

            $moved = [];
            $now = now();

            // 1) Öğrenci bağları
            $moved['guardian_student'] = $this->moveStudentLinks($target, $source, $now);

            // 2) Doğrudan referanslar (finans + talepler)
            foreach (self::FOREIGN_KEYS as $table => [$column]) {
                if ($this->hasColumn($table, $column)) {
                    $moved[$table] = DB::table($table)->where($column, $source->id)->update([$column => $target->id]);
                }
            }

            // 3) Çok biçimli referanslar (fatura alıcısı, yevmiye cari, mesaj geçmişi …)
            foreach (self::MORPHS as $table => [$typeCol, $idCol]) {
                if ($this->hasColumn($table, $typeCol) && $this->hasColumn($table, $idCol)) {
                    $moved[$table] = DB::table($table)->whereIn($typeCol, self::guardianTypes())->where($idCol, $source->id)->update([$idCol => $target->id]);
                }
            }

            // 4) Etiketler (birincil anahtar çakışmasın)
            $moved['taggables'] = $this->moveTags($target, $source);

            // 5) İleti onayları: hedefte olmayan kanal/amaç taşınır; ikisinde de varsa daha yeni kayıt geçerli olur
            $moved['communication_consents'] = $this->moveConsents($target, $source);

            // 6) Portal hesabı
            $account = $this->moveAccount($target, $source);

            // 7) Hedefte boş olan iletişim/kimlik alanları kaynaktan doldurulur
            $before = $target->only(array_merge(array_keys(self::FILLABLE), ['national_id_last4', 'notes']));
            foreach (array_keys(self::FILLABLE) as $f) {
                if (blank($target->{$f}) && filled($source->{$f})) {
                    $target->{$f} = $source->{$f};
                }
            }
            if (blank($target->national_id_hash) && filled($source->national_id_hash)) {
                $target->forceFill(['national_id_encrypted' => $source->national_id_encrypted, 'national_id_hash' => $source->national_id_hash, 'national_id_last4' => $source->national_id_last4]);
            }
            if (filled($source->notes)) {
                $note = sprintf('[%s birleştirilen veli #%d] %s', $now->format('d.m.Y'), $source->id, $source->notes);
                $target->notes = mb_substr(trim(($target->notes ? $target->notes."\n" : '').$note), 0, 2000);
            }
            $target->save();

            // 8) Kaynak veli soft delete (kayıt silinmez)
            $sourceNotes = mb_substr(trim(($source->notes ? $source->notes."\n" : '').sprintf('[%s] #%d numaralı veliyle birleştirildi.', $now->format('d.m.Y'), $target->id)), 0, 2000);
            $source->forceFill(['notes' => $sourceNotes])->save();
            $source->delete();

            // 9) Hedef hesabın aktifliği yeniden hesaplanır (yoksa açılmaya çalışılır)
            $this->accounts->ensure($target->fresh(), audit: false);
            $this->accounts->syncActive($target->fresh(), audit: false);

            $moved = array_filter($moved, fn ($n) => $n > 0);
            $changes = [
                'source_id' => $source->id,
                'target_id' => $target->id,
                'source' => ['name' => $source->full_name, 'phone' => Sensitive::maskPhone($source->phone), 'user_id' => $plan['source']['user_id']],
                'moved' => $moved,
                'account' => $account,
                'before' => $before,
                'after' => $target->only(array_keys($before)),
            ];
            Audit::log('guardian.merged', sprintf('%s (#%d) velisini %s (#%d) velisiyle birleştirdi: %s.', $source->full_name, $source->id, $target->full_name, $target->id, $this->movedText($moved)), $target, $changes);
            Audit::log('guardian.merged_away', sprintf('%s (#%d) velisi #%d numaralı veliye katıldı ve arşivlendi.', $source->full_name, $source->id, $target->id), $source, ['target_id' => $target->id]);

            return [
                'target_id' => $target->id,
                'source_id' => $source->id,
                'moved' => $moved,
                'message' => sprintf('%s, %s ile birleştirildi.', $source->full_name, $target->full_name),
            ];
        });
    }

    // ================================================================== plan

    private function plan(Guardian $target, Guardian $source): array
    {
        $counts = [];
        $financial = [];
        foreach (self::FOREIGN_KEYS as $table => [$column, $label, $isFinance]) {
            if ($this->hasColumn($table, $column)) {
                $n = DB::table($table)->where($column, $source->id)->count();
                $counts[] = ['key' => $table, 'label' => $label, 'count' => $n, 'financial' => $isFinance];
            }
        }
        foreach (self::MORPHS as $table => [$typeCol, $idCol, $label, $isFinance]) {
            if ($this->hasColumn($table, $typeCol) && $this->hasColumn($table, $idCol)) {
                $n = DB::table($table)->whereIn($typeCol, self::guardianTypes())->where($idCol, $source->id)->count();
                $counts[] = ['key' => $table, 'label' => $label, 'count' => $n, 'financial' => $isFinance];
            }
        }
        if ($this->hasTable('taggables')) {
            $counts[] = ['key' => 'taggables', 'label' => 'Etiket', 'count' => DB::table('taggables')->whereIn('taggable_type', self::guardianTypes())->where('taggable_id', $source->id)->count(), 'financial' => false];
        }
        if ($this->hasTable('communication_consents')) {
            $counts[] = ['key' => 'communication_consents', 'label' => 'İleti izni', 'count' => DB::table('communication_consents')->whereIn('consentable_type', self::guardianTypes())->where('consentable_id', $source->id)->count(), 'financial' => false];
        }

        $targetLinks = $this->links($target->id);
        $sourceLinks = $this->links($source->id);
        $students = $sourceLinks->map(fn ($l) => [
            'student_id' => (int) $l->student_id,
            'student' => $l->full_name,
            'relationship' => $l->relationship,
            'action' => $targetLinks->has($l->student_id) ? 'merge' : 'move',
        ])->values()->all();

        $fills = [];
        foreach (self::FILLABLE as $f => $label) {
            if (blank($target->{$f}) && filled($source->{$f})) {
                $fills[] = ['field' => $f, 'label' => $label];
            }
        }
        if (blank($target->national_id_hash) && filled($source->national_id_hash)) {
            $fills[] = ['field' => 'national_id', 'label' => 'TC kimlik no'];
        }

        $warnings = [];
        if (mb_strtolower(trim($target->full_name)) !== mb_strtolower(trim($source->full_name))) {
            $warnings[] = sprintf('Adlar farklı: "%s" ve "%s". Aynı kişi olduğundan emin olun.', $target->full_name, $source->full_name);
        }
        $tk = array_filter([self::phoneKey($target->phone), self::phoneKey($target->whatsapp_phone)]);
        $sk = array_filter([self::phoneKey($source->phone), self::phoneKey($source->whatsapp_phone)]);
        if (! array_intersect($tk, $sk)) {
            $warnings[] = 'Telefon numaraları eşleşmiyor.';
        }
        if ($target->national_id_hash && $source->national_id_hash && $target->national_id_hash !== $source->national_id_hash) {
            $warnings[] = 'TC kimlik numaraları FARKLI — büyük olasılıkla iki ayrı kişi.';
        }

        $accountPlan = match (true) {
            $source->user_id && ! $target->user_id => 'move',
            $source->user_id && $target->user_id => 'deactivate_source',
            default => 'none',
        };

        return [
            'target' => $this->summary($target, $targetLinks),
            'source' => $this->summary($source, $sourceLinks),
            'students' => $students,
            'counts' => $counts,
            'financial_total' => array_sum(array_map(fn ($c) => $c['financial'] ? $c['count'] : 0, $counts)),
            'fills' => $fills,
            'account' => $accountPlan,
            'warnings' => $warnings,
            'blocking' => $target->national_id_hash && $source->national_id_hash && $target->national_id_hash !== $source->national_id_hash,
        ];
    }

    private function summary(Guardian $g, Collection $links): array
    {
        $user = $g->user_id ? User::query()->withTrashed()->find($g->user_id, ['id', 'username', 'is_active', 'last_login_at']) : null;

        return [
            'id' => $g->id,
            'name' => $g->full_name,
            'phone' => Sensitive::maskPhone($g->phone),
            'email' => $g->email,
            'occupation' => $g->occupation,
            'created_at' => $g->created_at?->toAtomString(),
            'user_id' => $g->user_id,
            'portal' => $user ? ['is_active' => (bool) $user->is_active, 'last_login_at' => $user->last_login_at?->toAtomString()] : null,
            'students' => $links->map(fn ($l) => ['id' => (int) $l->student_id, 'full_name' => $l->full_name, 'relationship' => $l->relationship, 'is_primary' => (bool) $l->is_primary])->values()->all(),
        ];
    }

    private function fingerprint(array $plan): string
    {
        $basis = [
            $plan['target']['id'], $plan['source']['id'], $plan['target']['user_id'], $plan['source']['user_id'],
            array_map(fn ($c) => $c['key'].':'.$c['count'], $plan['counts']),
            array_map(fn ($s) => $s['student_id'].':'.$s['action'], $plan['students']),
        ];

        return hash('sha256', json_encode($basis));
    }

    // ================================================================== adımlar

    /** @return array{0: Guardian, 1: Guardian} */
    private function pair(int $targetId, int $sourceId): array
    {
        if ($targetId === $sourceId) {
            throw new BusinessRuleException('Bir veli kendisiyle birleştirilemez.', 'merge_same_guardian', [], 422);
        }
        $target = Guardian::query()->find($targetId);
        $source = Guardian::query()->find($sourceId);
        if (! $target || ! $source) {
            throw new BusinessRuleException('Birleştirilecek velilerden biri bulunamadı (silinmiş ya da başka şubede olabilir).', 'merge_guardian_missing', [], 404);
        }
        if ((int) $target->branch_id !== (int) $source->branch_id) {
            throw new BusinessRuleException('Farklı şubelerdeki veliler birleştirilemez.', 'merge_branch_mismatch', [], 422);
        }

        return [$target, $source];
    }

    private function moveStudentLinks(Guardian $target, Guardian $source, $now): int
    {
        $targetLinks = $this->links($target->id);
        $n = 0;
        foreach ($this->links($source->id) as $link) {
            $existing = $targetLinks->get($link->student_id);
            if ($existing) {
                DB::table('guardian_student')->where('id', $existing->id)->update([
                    'is_primary' => (bool) $existing->is_primary || (bool) $link->is_primary,
                    'is_financially_responsible' => (bool) $existing->is_financially_responsible || (bool) $link->is_financially_responsible,
                    'receives_notifications' => (bool) $existing->receives_notifications || (bool) $link->receives_notifications,
                    'relationship' => in_array($existing->relationship, [null, '', 'parent', 'other'], true) && ! in_array($link->relationship, [null, '', 'parent', 'other'], true)
                        ? $link->relationship : $existing->relationship,
                    'updated_at' => $now,
                ]);
                DB::table('guardian_student')->where('id', $link->id)->delete();
            } else {
                DB::table('guardian_student')->where('id', $link->id)->update(['guardian_id' => $target->id, 'updated_at' => $now]);
            }
            $n++;
        }

        return $n;
    }

    private function moveTags(Guardian $target, Guardian $source): int
    {
        if (! $this->hasTable('taggables')) {
            return 0;
        }
        $types = self::guardianTypes();
        $have = DB::table('taggables')->whereIn('taggable_type', $types)->where('taggable_id', $target->id)->pluck('tag_id')->map(fn ($v) => (int) $v)->all();
        $n = 0;
        foreach (DB::table('taggables')->whereIn('taggable_type', $types)->where('taggable_id', $source->id)->get() as $row) {
            $q = DB::table('taggables')->where('tag_id', $row->tag_id)->where('taggable_type', $row->taggable_type)->where('taggable_id', $source->id);
            if (in_array((int) $row->tag_id, $have, true)) {
                $q->delete();
            } else {
                $q->update(['taggable_id' => $target->id]);
                $have[] = (int) $row->tag_id;
            }
            $n++;
        }

        return $n;
    }

    private function moveConsents(Guardian $target, Guardian $source): int
    {
        if (! $this->hasTable('communication_consents')) {
            return 0;
        }
        $types = self::guardianTypes();
        $mine = DB::table('communication_consents')->whereIn('consentable_type', $types)->where('consentable_id', $target->id)->get()
            ->keyBy(fn ($r) => $r->channel.'|'.$r->purpose);
        $n = 0;
        foreach (DB::table('communication_consents')->whereIn('consentable_type', $types)->where('consentable_id', $source->id)->get() as $row) {
            $existing = $mine->get($row->channel.'|'.$row->purpose);
            if (! $existing) {
                DB::table('communication_consents')->where('id', $row->id)->update(['consentable_id' => $target->id]);
                $n++;
            } elseif (strtotime((string) $row->recorded_at) > strtotime((string) $existing->recorded_at)) {
                // Kaynaktaki onay daha yeni: hedefin kaydı güncellenir, kaynak kayıt delil olarak arşiv veliye bağlı kalır
                DB::table('communication_consents')->where('id', $existing->id)->update([
                    'granted' => $row->granted, 'source' => mb_substr('Birleştirme: '.($row->source ?? ''), 0, 60), 'recorded_at' => $row->recorded_at, 'recorded_by' => $row->recorded_by,
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** @return array{plan:string, user_id:?int, deactivated_user_id?:int, moved_requests?:int, moved_notifications?:int} */
    private function moveAccount(Guardian $target, Guardian $source): array
    {
        if (! $source->user_id) {
            return ['plan' => 'none', 'user_id' => $target->user_id];
        }
        $sourceUserId = (int) $source->user_id;

        if (! $target->user_id) {
            // Kaynağın portal hesabı hedefe geçer (kullanıcı adı/şifre aynı kalır)
            Guardian::query()->withoutGlobalScopes()->whereKey($source->id)->update(['user_id' => null]);
            $source->setAttribute('user_id', null);
            $source->syncOriginalAttribute('user_id');
            Guardian::query()->withoutGlobalScopes()->whereKey($target->id)->update(['user_id' => $sourceUserId]);
            $target->setAttribute('user_id', $sourceUserId);
            $target->syncOriginalAttribute('user_id');
            User::query()->whereKey($sourceUserId)->where('user_type', User::TYPE_GUARDIAN)->update(['name' => $target->full_name, 'updated_at' => now()]);

            return ['plan' => 'move', 'user_id' => $sourceUserId];
        }

        // İkisinin de hesabı var: hedefinki kalır; kaynağın hesabı kapatılır, talepleri ve bildirimleri hedef hesaba geçer
        $targetUserId = (int) $target->user_id;
        PortalAccounts::setActive($sourceUserId, User::TYPE_GUARDIAN, false);
        $requests = $this->hasColumn('contact_requests', 'user_id')
            ? DB::table('contact_requests')->where('user_id', $sourceUserId)->update(['user_id' => $targetUserId]) : 0;
        $notifications = $this->hasTable('app_notifications')
            ? DB::table('app_notifications')->where('user_id', $sourceUserId)->update(['user_id' => $targetUserId]) : 0;

        return ['plan' => 'deactivate_source', 'user_id' => $targetUserId, 'deactivated_user_id' => $sourceUserId, 'moved_requests' => $requests, 'moved_notifications' => $notifications];
    }

    // ================================================================== yardımcılar

    /** @return Collection<int, object> öğrenci id => bağ satırı */
    private function links(int $guardianId): Collection
    {
        return DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')
            ->where('gs.guardian_id', $guardianId)
            ->orderBy('s.full_name')
            ->get(['gs.id', 'gs.student_id', 'gs.relationship', 'gs.is_primary', 'gs.is_financially_responsible', 'gs.receives_notifications', 's.full_name'])
            ->keyBy('student_id');
    }

    /** @return list<string> veli için kullanılmış tür adları (morf haritası + sınıf adı) */
    public static function guardianTypes(): array
    {
        return array_values(array_unique(['guardian', (new Guardian)->getMorphClass(), Guardian::class]));
    }

    private function movedText(array $moved): string
    {
        if ($moved === []) {
            return 'taşınacak kayıt yoktu';
        }
        $labels = ['guardian_student' => 'öğrenci bağı', 'taggables' => 'etiket', 'communication_consents' => 'ileti izni'];
        foreach (self::FOREIGN_KEYS as $t => [, $label]) {
            $labels[$t] = mb_strtolower($label);
        }
        foreach (self::MORPHS as $t => [, , $label]) {
            $labels[$t] = mb_strtolower($label);
        }

        return implode(', ', array_map(fn ($t, $n) => "{$n} ".($labels[$t] ?? $t), array_keys($moved), $moved));
    }

    private function hasTable(string $table): bool
    {
        return $this->schemaCache[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->schemaCache["{$table}.{$column}"] ??= ($this->hasTable($table) && Schema::hasColumn($table, $column));
    }
}
