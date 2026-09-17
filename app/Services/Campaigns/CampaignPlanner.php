<?php

namespace App\Services\Campaigns;

use App\Models\CommunicationConsent;
use App\Models\CommunicationSuppression;
use App\Services\Messaging\Sms\SmsLength;
use App\Support\Settings;

/**
 * Kişi listesi + içerik → kanal bazında gönderim planı (kim alacak, kim neden atlanacak, kaç SMS parçası).
 * Önizleme (DB'ye yazmadan) ve onay (alıcı satırları) aynı hesabı kullanır.
 *
 * Kurallar:
 *  - Adres yok / geçersiz → atlanır (SMS için yalnız 905xxxxxxxxx cep numarası).
 *  - Ret listesindeki adres (abonelikten çıkmış, RET) → her toplu gönderimde atlanır.
 *  - Ticari ileti: yalnız ilgili kanalda "ticari ileti onayı VERİLMİŞ" kişiler (elle eklenenlerin onay kaydı olamaz → atlanır).
 *  - Bilgilendirme: kişi o kanalda bilgilendirme iletisini açıkça reddetmişse atlanır.
 *  - Aynı kanalda aynı adres ikinci kez gelirse "tekrar eden" olarak atlanır (ilk kişi kalır).
 */
final class CampaignPlanner
{
    public const SAMPLE_LIMIT = 6;

    /**
     * @param  list<array>  $people  CampaignAudience::resolve() çıktısı
     * @param  array{channels: list<string>, is_commercial: bool, sms_body: ?string, email_subject: ?string, email_body: ?string, options?: array}  $content
     * @param  array{sms?: array, email?: array}  $configs  entegrasyon ayarları (encoding, unit_price, ret_number …)
     * @return array{rows: list<array>, summary: array}
     */
    public function plan(int $branchId, array $people, array $content, array $configs = []): array
    {
        $channels = array_values(array_intersect(['sms', 'email'], $content['channels'] ?? []));
        $commercial = (bool) ($content['is_commercial'] ?? false);
        $smsConfig = $configs['sms'] ?? [];
        $mode = in_array($smsConfig['encoding'] ?? 'tr', SmsLength::MODES, true) ? ($smsConfig['encoding'] ?? 'tr') : 'tr';
        $institution = Settings::group('institution', $branchId);

        $optOut = null;
        if (in_array('sms', $channels, true) && ($commercial || ! empty($content['options']['sms_opt_out']))) {
            $optOut = CampaignText::smsOptOut($smsConfig);
        }

        [$granted, $denied] = $this->consents($people, $channels);
        $suppressed = $this->suppressions($branchId, $channels);

        $rows = [];
        $seen = [];
        $summary = ['people' => count($people), 'by_group' => [], 'channels' => [], 'samples' => [], 'unknown_vars' => [], 'opt_out_text' => $optOut];

        foreach ($people as $p) {
            $summary['by_group'][$p['group']] = ($summary['by_group'][$p['group']] ?? 0) + 1;
        }

        foreach ($channels as $channel) {
            $stats = ['total' => 0, 'sendable' => 0, 'no_address' => 0, 'invalid' => 0, 'no_consent' => 0, 'suppressed' => 0, 'denied' => 0, 'duplicate' => 0];
            if ($channel === 'sms') {
                $stats += ['parts' => 0, 'max_parts' => 0, 'encoding' => null, 'unit_price' => self::price($smsConfig['unit_price'] ?? null), 'cost' => null];
            }

            foreach ($people as $p) {
                $stats['total']++;
                $address = $channel === 'sms' ? $p['phone'] : $p['email'];
                $key = $p['type'] ? $p['type'].':'.$p['id'] : null;
                $reason = null;

                if (! $address) {
                    $reason = 'no_address';
                } elseif ($channel === 'sms' && ! preg_match('/^905\d{9}$/', $address)) {
                    $reason = 'invalid';
                } elseif ($channel === 'email' && ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $reason = 'invalid';
                } elseif (isset($suppressed[$channel][$address])) {
                    $reason = 'suppressed';
                } elseif ($commercial && (! $key || ! isset($granted[$channel][$key]))) {
                    $reason = 'no_consent';
                } elseif (! $commercial && $key && isset($denied[$channel][$key])) {
                    $reason = 'denied';
                } elseif (isset($seen[$channel][$address])) {
                    $reason = 'duplicate';
                }

                $vars = CampaignText::varsFor($p, $institution);
                $row = [
                    'channel' => $channel, 'group' => $p['group'], 'recipient_type' => $p['type'], 'recipient_id' => $p['id'],
                    'student_id' => $p['student_id'], 'name' => mb_substr($p['name'], 0, 160), 'to' => $address ? mb_substr($address, 0, 160) : null,
                    'vars' => $vars, 'status' => $reason ? 'skipped' : 'pending', 'skip_reason' => $reason, 'sms_parts' => null,
                ];

                if ($reason) {
                    $stats[$reason]++;
                } else {
                    $seen[$channel][$address] = true;
                    $stats['sendable']++;
                    if ($channel === 'sms') {
                        $analysis = SmsLength::analyze(self::smsText($content['sms_body'] ?? '', $vars, $optOut), $mode);
                        $row['sms_parts'] = $analysis['parts'];
                        $stats['parts'] += $analysis['parts'];
                        $stats['max_parts'] = max($stats['max_parts'], $analysis['parts']);
                        $stats['encoding'] = self::widerEncoding($stats['encoding'], $analysis['encoding']);
                    }
                    if (count($summary['samples']) < self::SAMPLE_LIMIT && ! $this->hasSample($summary['samples'], $p)) {
                        $summary['samples'][] = $this->sample($p, $vars, $content, $optOut, $mode);
                    }
                }

                $rows[] = $row;
            }

            if ($channel === 'sms' && $stats['unit_price'] !== null) {
                $stats['cost'] = round($stats['parts'] * $stats['unit_price'], 2);
            }
            $summary['channels'][$channel] = $stats;
        }

        $summary['unknown_vars'] = array_values(array_unique(array_merge(
            in_array('sms', $channels, true) ? CampaignText::unknown($content['sms_body'] ?? '') : [],
            in_array('email', $channels, true) ? CampaignText::unknown(($content['email_subject'] ?? '').' '.($content['email_body'] ?? '')) : [],
        )));
        // Aynı kişi birden çok kanalda: tekil kişi sayısı
        $summary['reachable_people'] = count(array_unique(array_map(
            fn ($r) => $r['recipient_type'] ? $r['recipient_type'].':'.$r['recipient_id'] : 'm:'.$r['to'],
            array_filter($rows, fn ($r) => $r['status'] === 'pending'),
        )));

        return ['rows' => $rows, 'summary' => $summary];
    }

    public static function smsText(?string $body, array $vars, ?string $optOut): string
    {
        $text = rtrim(CampaignText::render($body ?? '', $vars));

        return $optOut ? $text.' '.$optOut : $text;
    }

    private static function price(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = (float) str_replace(',', '.', (string) $value);

        return $v > 0 ? $v : null;
    }

    private static function widerEncoding(?string $a, string $b): string
    {
        $rank = ['gsm7' => 0, 'tr' => 1, 'unicode' => 2];

        return $a === null || $rank[$b] > $rank[$a] ? $b : $a;
    }

    private function hasSample(array $samples, array $p): bool
    {
        foreach ($samples as $s) {
            if ($s['key'] === ($p['type'] ? $p['type'].':'.$p['id'] : 'm:'.$p['name'])) {
                return true;
            }
        }

        return false;
    }

    private function sample(array $p, array $vars, array $content, ?string $optOut, string $mode): array
    {
        $sms = in_array('sms', $content['channels'] ?? [], true) ? self::smsText($content['sms_body'] ?? '', $vars, $optOut) : null;

        return [
            'key' => $p['type'] ? $p['type'].':'.$p['id'] : 'm:'.$p['name'],
            'name' => $p['name'],
            'group' => $p['group'],
            'sms' => $sms,
            'sms_analysis' => $sms !== null ? array_diff_key(SmsLength::analyze($sms, $mode), ['text' => true]) : null,
            'email_subject' => in_array('email', $content['channels'] ?? [], true) ? CampaignText::render($content['email_subject'] ?? '', $vars) : null,
            'email_body' => in_array('email', $content['channels'] ?? [], true) ? CampaignText::render($content['email_body'] ?? '', $vars) : null,
        ];
    }

    /**
     * Toplu izin okuması (kişi başı sorgu yok).
     *
     * @return array{0: array<string, array<string, true>>, 1: array<string, array<string, true>>} [ticari onaylı, bilgilendirme reddi]
     */
    private function consents(array $people, array $channels): array
    {
        $byType = [];
        foreach ($people as $p) {
            if ($p['type']) {
                $byType[$p['type']][] = $p['id'];
            }
        }

        $granted = [];
        $denied = [];
        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_unique($ids), 1000) as $chunk) {
                $rows = CommunicationConsent::query()
                    ->where('consentable_type', $type)->whereIn('consentable_id', $chunk)
                    ->whereIn('channel', $channels)
                    ->get(['consentable_type', 'consentable_id', 'channel', 'purpose', 'granted']);
                foreach ($rows as $r) {
                    $key = $r->consentable_type.':'.$r->consentable_id;
                    if ($r->purpose === 'marketing' && $r->granted) {
                        $granted[$r->channel][$key] = true;
                    }
                    if ($r->purpose === 'informational' && ! $r->granted) {
                        $denied[$r->channel][$key] = true;
                    }
                }
            }
        }

        return [$granted, $denied];
    }

    /** @return array<string, array<string, true>> */
    private function suppressions(int $branchId, array $channels): array
    {
        $out = [];
        CommunicationSuppression::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->whereIn('channel', $channels)
            ->get(['channel', 'address'])
            ->each(function ($r) use (&$out) {
                $out[$r->channel][$r->address] = true;
            });

        return $out;
    }
}
