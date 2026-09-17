<?php

namespace App\Services\Campaigns;

use App\Models\CommunicationSuppression;
use Illuminate\Support\Facades\DB;

/**
 * Ticari ileti onayları (communication_consents, purpose=marketing) ve ret listesi.
 * NOT: Gerçek İYS (İleti Yönetim Sistemi) kaydı sağlayıcı/İYS panelinden yapılır; buradaki kayıt kurumun
 * kendi onay delilidir (kaynak + tarih + kaydeden). Sağlayıcıya gönderimde İYS filtresi ayrıca uygulanır.
 */
class ConsentService
{
    public const TYPES = ['student', 'guardian', 'teacher', 'employee', 'lead'];

    /**
     * @param  list<array{type:string, id:int}>  $people
     * @param  list<string>  $channels  sms | email
     */
    public function record(array $people, array $channels, bool $granted, string $source, ?int $userId): int
    {
        $count = 0;
        $now = now();
        DB::transaction(function () use ($people, $channels, $granted, $source, $userId, $now, &$count) {
            foreach ($people as $p) {
                foreach ($channels as $channel) {
                    DB::table('communication_consents')->updateOrInsert(
                        ['consentable_type' => $p['type'], 'consentable_id' => $p['id'], 'channel' => $channel, 'purpose' => 'marketing'],
                        ['granted' => $granted, 'source' => mb_substr($source, 0, 60), 'recorded_at' => $now, 'recorded_by' => $userId],
                    );
                    $count++;
                }
            }
        });

        return $count;
    }

    /** Kayıt formlarında sorulan ticari ileti kanalları */
    public const FORM_CHANNELS = ['sms', 'email', 'whatsapp'];

    public const FORM_SOURCE = 'Kayıt formu';

    /**
     * Kişilerin ticari ileti (marketing) onay durumları.
     *
     * @param  list<int>  $ids
     * @return array<int, array{sms:bool, email:bool, whatsapp:bool}>
     */
    public function marketingState(string $type, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = array_fill_keys(self::FORM_CHANNELS, false);
        }
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('communication_consents')->where('consentable_type', $type)->whereIn('consentable_id', $ids)
            ->where('purpose', 'marketing')->whereIn('channel', self::FORM_CHANNELS)->get(['consentable_id', 'channel', 'granted']);
        foreach ($rows as $r) {
            $out[(int) $r->consentable_id][$r->channel] = (bool) $r->granted;
        }

        return $out;
    }

    /**
     * Formdan gelen ticari ileti onaylarını yazar. Yalnız DEĞİŞEN kanallar kaydedilir (mevcut delilin tarihi korunur);
     * hiç kaydı olmayan kanal için "hayır" yazılmaz (kayıt yok = onay yok).
     *
     * @param  array<string, mixed>  $flags  ['sms' => bool, 'email' => bool, 'whatsapp' => bool] (eksik anahtar = dokunma)
     * @return int yazılan kayıt sayısı
     */
    public function syncFromForm(string $type, int $id, array $flags, ?int $userId, string $source = self::FORM_SOURCE): int
    {
        $current = DB::table('communication_consents')->where('consentable_type', $type)->where('consentable_id', $id)
            ->where('purpose', 'marketing')->pluck('granted', 'channel');
        $grant = [];
        $deny = [];
        foreach (self::FORM_CHANNELS as $channel) {
            if (! array_key_exists($channel, $flags) || $flags[$channel] === null) {
                continue;
            }
            $want = filter_var($flags[$channel], FILTER_VALIDATE_BOOLEAN);
            $has = $current->has($channel) ? (bool) $current[$channel] : null;
            if ($has === $want || ($has === null && ! $want)) {
                continue;
            }
            $want ? $grant[] = $channel : $deny[] = $channel;
        }
        $n = 0;
        if ($grant) {
            $n += $this->record([['type' => $type, 'id' => $id]], $grant, true, $source, $userId);
        }
        if ($deny) {
            $n += $this->record([['type' => $type, 'id' => $id]], $deny, false, $source, $userId);
        }

        return $n;
    }

    /** Abonelikten çıkma / RET: adres ret listesine girer, kişi biliniyorsa ticari onayı da kapanır. */
    public function optOut(int $branchId, string $channel, ?string $type, ?int $id, string $address, string $reason, string $source, ?int $userId = null): void
    {
        $address = CommunicationSuppression::normalize($channel, $address);
        if ($address === '') {
            return;
        }

        DB::transaction(function () use ($branchId, $channel, $type, $id, $address, $reason, $source, $userId) {
            CommunicationSuppression::query()->withoutGlobalScope('branch')->updateOrCreate(
                ['branch_id' => $branchId, 'channel' => $channel, 'address' => $address],
                ['reason' => $reason, 'source' => mb_substr($source, 0, 120), 'recipient_type' => $type, 'recipient_id' => $id, 'recorded_by' => $userId],
            );

            if ($type && $id && in_array($type, self::TYPES, true)) {
                DB::table('communication_consents')->updateOrInsert(
                    ['consentable_type' => $type, 'consentable_id' => $id, 'channel' => $channel, 'purpose' => 'marketing'],
                    ['granted' => false, 'source' => mb_substr($source, 0, 60), 'recorded_at' => now(), 'recorded_by' => $userId],
                );
            }
        });
    }
}
