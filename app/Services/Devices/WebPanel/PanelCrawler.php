<?php

namespace App\Services\Devices\WebPanel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CİHAZ WEB PANELİ KEŞFİ (Perkotek YT33 "Dynamic Face" — kullanıcı panele tarayıcıyla girebiliyor).
 *
 * GÜVENLİK KURALLARI (sıkı):
 *   • YALNIZ GET ile gezer. Tek istisna: bulunan GİRİŞ formuna oturum açmak için tek POST.
 *   • Başka HİÇBİR form gönderilmez (kullanıcı ekle/sil/ayar değiştir POST'ları YASAK).
 *   • Hiçbir ayar değiştirilmez. Aynı köken (host:port) dışına çıkılmaz. En çok ~60 sayfa / derinlik 3.
 *   • Parola şifreli saklanır, sonuç/loglarda '***'.
 *
 * Sonuç: sayfa ağacı, uç noktalar (form action + method + alanlar, <a> bağlantıları, JS içi fetch/XHR URL desenleri),
 * kaynak dosyalar (HTML/JS/CSS özet + kısaltılmış içerik). Uç noktalar UYDURULMAZ; ancak gerçekten bulunanlar döner.
 */
class PanelCrawler
{
    public const MAX_PAGES = 60;

    public const MAX_DEPTH = 3;

    private array $visited = [];

    private array $pages = [];

    private array $assets = [];

    private array $endpoints = [];

    private array $forms = [];

    private array $log = [];

    private string $base = '';

    private ?string $host = null;

    private $cookies = null;

    public function crawl(string $ip, int $port, string $scheme, ?string $user, #[\SensitiveParameter] ?string $password): array
    {
        $this->base = "{$scheme}://{$ip}".(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) ? '' : ":{$port}");
        $this->host = "{$ip}:{$port}";
        $this->cookies = new \GuzzleHttp\Cookie\CookieJar();
        Http::allowStrayRequests();

        $loginResult = $this->tryLogin($user, $password);

        $queue = [['/', 0]];
        while ($queue !== [] && count($this->pages) < self::MAX_PAGES) {
            [$path, $depth] = array_shift($queue);
            $url = $this->absolute($path);
            if ($url === null || isset($this->visited[$url]) || $depth > self::MAX_DEPTH) {
                continue;
            }
            $this->visited[$url] = true;

            $res = $this->get($url);
            if ($res === null) {
                continue;
            }
            [$status, $type, $body] = $res;

            if (str_contains($type, 'html')) {
                $this->pages[] = ['url' => $this->rel($url), 'durum' => $status, 'boyut' => strlen($body), 'baslik' => $this->title($body)];
                foreach ($this->extractForms($body, $url) as $form) {
                    $this->forms[] = $form;
                }
                foreach ($this->extractLinks($body, $url) as $link) {
                    if (! isset($this->visited[$link])) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
                foreach ($this->extractAssets($body, $url) as $asset) {
                    $queue[] = [$asset, $depth + 1];
                }
            } elseif (str_contains($type, 'javascript') || str_contains($type, 'css') || str_ends_with(strtolower($url), '.js') || str_ends_with(strtolower($url), '.css')) {
                $this->assets[] = ['url' => $this->rel($url), 'tur' => str_contains($type, 'css') ? 'css' : 'js', 'boyut' => strlen($body), 'icerik' => mb_substr($body, 0, 40000)];
                foreach ($this->extractJsEndpoints($body) as $ep) {
                    $this->endpoints[$ep] = ['url' => $ep, 'kaynak' => 'js', 'yontem' => 'bilinmiyor'];
                }
            }
        }

        // Form action'ları da uç nokta listesine (yalnız kayıt; GET olanlar okunabilir aday)
        foreach ($this->forms as $f) {
            $key = strtoupper($f['method']).' '.$f['action'];
            $this->endpoints[$key] = ['url' => $f['action'], 'kaynak' => 'form', 'yontem' => strtoupper($f['method']), 'alanlar' => array_column($f['alanlar'], 'ad')];
        }

        $summary = [
            'hedef' => $this->base,
            'giris' => $loginResult,
            'sayfa_sayisi' => count($this->pages),
            'uc_sayisi' => count($this->endpoints),
            'olasi_okuma_uclari' => $this->readCandidates(),
        ];

        Log::channel('terminal')->info('Web paneli keşfi', ['hedef' => $this->host, 'sayfa' => count($this->pages), 'uc' => count($this->endpoints), 'giris' => $loginResult['durum'] ?? '?']);

        return [
            'ozet' => $summary,
            'sayfalar' => $this->pages,
            'uc_noktalari' => array_values($this->endpoints),
            'formlar' => $this->forms,
            'kaynaklar' => $this->assets,
            'gunluk' => $this->log,
        ];
    }

    private function tryLogin(?string $user, ?string $password): array
    {
        $home = $this->get($this->base.'/');
        if ($home === null) {
            return ['durum' => 'ulasilamadi', 'mesaj' => 'Panele ulaşılamadı ('.$this->host.'). IP, port ve ağ bağlantısını kontrol edin.'];
        }

        [, $type, $body] = $home;
        if (! str_contains($type, 'html')) {
            return ['durum' => 'html_degil', 'mesaj' => 'Kök sayfa HTML döndürmedi.'];
        }

        $form = $this->loginForm($body);
        if ($form === null) {
            return ['durum' => 'giris_formu_yok', 'mesaj' => 'Giriş formu bulunamadı; panel oturumsuz olabilir ya da JS ile giriş yapıyordur (keşif yine de sürer).'];
        }
        if ($user === null || $user === '') {
            return ['durum' => 'kimlik_gerekli', 'mesaj' => 'Giriş formu bulundu; panel kullanıcı adı ve şifresini girin.'];
        }

        $fields = [];
        foreach ($form['alanlar'] as $f) {
            $name = $f['ad'];
            $lower = strtolower($name.' '.($f['tur'] ?? ''));
            $fields[$name] = match (true) {
                str_contains($lower, 'pass') || str_contains($lower, 'pwd') || $f['tur'] === 'password' => (string) $password,
                str_contains($lower, 'user') || str_contains($lower, 'name') || str_contains($lower, 'login') || str_contains($lower, 'account') => (string) $user,
                default => $f['deger'] ?? '',
            };
        }

        try {
            $r = Http::withOptions(['cookies' => $this->cookies, 'verify' => false])->asForm()->timeout(15)
                ->post($form['action'], $fields);
            $this->log[] = ['t' => now()->format('H:i:s.v'), 'olay' => 'POST '.$this->rel($form['action']).' (giriş) → '.$r->status()];
            $ok = $r->successful() && ! $this->looksLikeLogin($r->body());

            return ['durum' => $ok ? 'basarili' : 'basarisiz', 'mesaj' => $ok ? 'Panel oturumu açıldı.' : 'Giriş denendi ama oturum doğrulanamadı (kullanıcı adı/şifre yanlış olabilir).', 'form' => $this->rel($form['action'])];
        } catch (Throwable $e) {
            return ['durum' => 'hata', 'mesaj' => 'Giriş sırasında hata: '.$e->getMessage()];
        }
    }

    /** @return array{0:int,1:string,2:string}|null */
    private function get(string $url): ?array
    {
        if (! str_starts_with($url, $this->base)) {
            return null;
        }
        try {
            $r = Http::withOptions(['cookies' => $this->cookies, 'verify' => false])->timeout(12)->get($url);
            $this->log[] = ['t' => now()->format('H:i:s.v'), 'olay' => 'GET '.$this->rel($url).' → '.$r->status()];

            return [$r->status(), strtolower($r->header('Content-Type') ?? ''), $r->body()];
        } catch (Throwable $e) {
            $this->log[] = ['t' => now()->format('H:i:s.v'), 'olay' => 'GET '.$this->rel($url).' → hata: '.$e->getMessage()];

            return null;
        }
    }

    private function loginForm(string $html): ?array
    {
        foreach ($this->extractForms($html, $this->base.'/') as $form) {
            foreach ($form['alanlar'] as $f) {
                if (($f['tur'] ?? '') === 'password' || str_contains(strtolower($f['ad']), 'pass') || str_contains(strtolower($f['ad']), 'pwd')) {
                    return $form;
                }
            }
        }

        return null;
    }

    private function looksLikeLogin(string $html): bool
    {
        return (bool) preg_match('/type=["\']password["\']/i', $html);
    }

    private function extractForms(string $html, string $pageUrl): array
    {
        $forms = [];
        if (! preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $m, PREG_SET_ORDER)) {
            return $forms;
        }
        foreach ($m as $f) {
            $action = $this->attr($f[1], 'action') ?: $pageUrl;
            $method = strtolower($this->attr($f[1], 'method') ?: 'get');
            $fields = [];
            if (preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $f[2], $im, PREG_SET_ORDER)) {
                foreach ($im as $inp) {
                    $name = $this->attr($inp[2], 'name');
                    if ($name) {
                        $fields[] = ['ad' => $name, 'tur' => $this->attr($inp[2], 'type') ?: $inp[1], 'deger' => $this->attr($inp[2], 'value')];
                    }
                }
            }
            $forms[] = ['sayfa' => $this->rel($pageUrl), 'action' => $this->absolute($action, $pageUrl) ?? $action, 'method' => $method, 'alanlar' => $fields];
        }

        return $forms;
    }

    private function extractLinks(string $html, string $pageUrl): array
    {
        $out = [];
        if (preg_match_all('/<a\b[^>]*href=["\']([^"\'#]+)["\']/i', $html, $m)) {
            foreach ($m[1] as $href) {
                if (($abs = $this->absolute($href, $pageUrl)) !== null) {
                    $out[] = $abs;
                }
            }
        }

        return array_unique($out);
    }

    private function extractAssets(string $html, string $pageUrl): array
    {
        $out = [];
        if (preg_match_all('/<script\b[^>]*src=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $src) {
                if (($abs = $this->absolute($src, $pageUrl)) !== null) {
                    $out[] = $abs;
                }
            }
        }

        return array_unique($out);
    }

    /** JS içindeki URL desenleri: fetch('…'), $.get/$.post/$.ajax({url:…}), XHR .open('GET','…'), "…cgi/…". */
    private function extractJsEndpoints(string $js): array
    {
        $out = [];
        $patterns = [
            '/\b(?:fetch|axios)\s*\(\s*["\']([^"\']+)["\']/i',
            '/\.(?:get|post|ajax|load)\s*\(\s*["\']([^"\']+)["\']/i',
            '/\.open\s*\(\s*["\'][A-Z]+["\']\s*,\s*["\']([^"\']+)["\']/i',
            '/url\s*:\s*["\']([^"\']+)["\']/i',
            '/["\']([\/A-Za-z0-9_.-]+\.(?:cgi|php|asp|do|json)(?:\?[^"\']*)?)["\']/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $js, $m)) {
                foreach ($m[1] as $u) {
                    $u = trim($u);
                    if ($u !== '' && ! str_starts_with($u, 'data:') && ! preg_match('#^https?://(?!'.preg_quote($this->host, '#').')#', $u) && strlen($u) < 200) {
                        $out[] = $u;
                    }
                }
            }
        }

        return array_unique($out);
    }

    /** Okuma (GET) adayı gibi görünen uçlar: kullanıcı listesi / kayıt / cihaz bilgisi / saat. */
    private function readCandidates(): array
    {
        $keywords = ['user', 'kullan', 'person', 'staff', 'employee', 'log', 'record', 'attend', 'punch', 'event', 'device', 'info', 'time', 'clock', 'data', 'list', 'query', 'get'];
        $out = [];
        foreach ($this->endpoints as $ep) {
            $u = strtolower($ep['url']);
            foreach ($keywords as $k) {
                if (str_contains($u, $k)) {
                    $out[] = $ep + ['ipucu' => $k];
                    break;
                }
            }
        }

        return array_values($out);
    }

    private function attr(string $tag, string $name): ?string
    {
        return preg_match('/\b'.$name.'\s*=\s*["\']([^"\']*)["\']/i', $tag, $m) ? html_entity_decode($m[1]) : null;
    }

    private function title(string $html): ?string
    {
        return preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : null;
    }

    private function absolute(string $href, ?string $pageUrl = null): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'data:')) {
            return null;
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return str_starts_with($href, $this->base) ? $this->strip($href) : null;
        }
        $base = $pageUrl ?: $this->base.'/';
        if (str_starts_with($href, '/')) {
            return $this->strip($this->base.$href);
        }
        $dir = preg_replace('#/[^/]*$#', '/', parse_url($base, PHP_URL_PATH) ?: '/');

        return $this->strip($this->base.$dir.$href);
    }

    private function strip(string $url): string
    {
        return preg_replace('/#.*$/', '', $url) ?? $url;
    }

    private function rel(string $url): string
    {
        return str_starts_with($url, $this->base) ? (substr($url, strlen($this->base)) ?: '/') : $url;
    }
}
