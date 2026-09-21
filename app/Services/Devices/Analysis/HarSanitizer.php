<?php

namespace App\Services\Devices\Analysis;

use App\Exceptions\BusinessRuleException;

/**
 * Cihaz web paneli tarayıcı kaydını (HAR) geliştiriciye gönderilmeden ÖNCE temizler (2026-09-21).
 *
 * Amaç: Perkotek YT33 "Dynamic Face" panelinin cihaza gönderdiği gerçek istekleri ve panelin JS dosyalarını
 * görmek (kullanıcı ekle/düzenle/sil uçları) — cihaz yerel ağda olduğu için sunucudan erişilemiyor.
 *
 * Silinen / maskelenen: Cookie, Set-Cookie, Authorization başlıkları ve çerez dizileri; sorgu, form ve JSON
 * gövdelerindeki parola benzeri alanlar ('***', HarImporter ile aynı kural). Yanıt içerikleri korunur
 * (JS dosyaları uç noktaları gösterir) ama onlardaki parola alanları da maskelenir.
 */
class HarSanitizer
{
    private const SECRET_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization'];

    /** @return array{json:string, istek:int, hostlar:list<string>} */
    public function temizle(string $ham): array
    {
        $har = json_decode($ham, true);
        if (! is_array($har) || ! isset($har['log']['entries']) || ! is_array($har['log']['entries'])) {
            throw new BusinessRuleException('Dosya geçerli bir HAR kaydı değil (tarayıcıda Ağ sekmesi › "HAR olarak kaydet").', 'har_invalid');
        }

        $imp = new HarImporter;
        $hostlar = [];

        foreach ($har['log']['entries'] as &$e) {
            foreach (['request', 'response'] as $taraf) {
                if (! isset($e[$taraf]) || ! is_array($e[$taraf])) {
                    continue;
                }
                $e[$taraf]['cookies'] = [];
                $e[$taraf]['headers'] = array_values(array_filter(
                    (array) ($e[$taraf]['headers'] ?? []),
                    fn ($h) => ! in_array(strtolower((string) ($h['name'] ?? '')), self::SECRET_HEADERS, true),
                ));
            }

            $url = (string) ($e['request']['url'] ?? '');
            if ($url !== '') {
                $p = parse_url($url) ?: [];
                if (isset($p['host'])) {
                    $hostlar[$p['host'].(isset($p['port']) ? ':'.$p['port'] : '')] = true;
                }
                if (isset($p['query'])) {
                    $e['request']['url'] = str_replace('?'.$p['query'], '?'.$imp->maskBody($p['query']), $url);
                }
            }
            foreach ((array) ($e['request']['queryString'] ?? []) as $i => $q) {
                if (preg_match('/(password|passwd|pass|pwd|pw|sifre|şifre|secret|token)/iu', (string) ($q['name'] ?? ''))) {
                    $e['request']['queryString'][$i]['value'] = '***';
                }
            }
            if (isset($e['request']['postData']['text'])) {
                $e['request']['postData']['text'] = $imp->maskBody((string) $e['request']['postData']['text']);
            }
            foreach ((array) ($e['request']['postData']['params'] ?? []) as $i => $q) {
                if (preg_match('/(password|passwd|pass|pwd|pw|sifre|şifre|secret|token)/iu', (string) ($q['name'] ?? ''))) {
                    $e['request']['postData']['params'][$i]['value'] = '***';
                }
            }
            if (isset($e['response']['content']['text']) && ($e['response']['content']['encoding'] ?? '') !== 'base64') {
                $e['response']['content']['text'] = $imp->maskBody((string) $e['response']['content']['text']);
            }
        }
        unset($e);

        return [
            'json' => json_encode($har, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'istek' => count($har['log']['entries']),
            'hostlar' => array_keys($hostlar),
        ];
    }
}
