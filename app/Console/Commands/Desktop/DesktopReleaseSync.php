<?php

namespace App\Console\Commands\Desktop;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sunucu: GitHub Releases'teki en yeni `desktop-v*` sürümünü web köküne (public/desktop) çeker.
 *
 * Depo özel olduğu için sürüm dosyaları herkese açık indirilemez; sunucu salt okur bir erişim anahtarıyla
 * (DESKTOP_GITHUB_TOKEN) dosyaları alır ve kendi alan adından statik sunar:
 *   /desktop/latest.json            Tauri güncelleyici bildirimi (url'ler bu sunucuya çevrilir)
 *   /desktop/release.json           /uygulamalar sayfası için özet (sürüm, DMG, boyut, özet, notlar)
 *   /desktop/changelog.json         masaüstü değişiklik günlüğü
 *   /desktop/<sürüm>/<dosya>        DMG (universal), güncelleme arşivleri + .sig:
 *                                   ErbaaKurs_<sürüm>_{aarch64,x86_64}.app.tar.gz (işlemciye özel, ~40 MB)
 *                                   ErbaaKurs_<sürüm>_universal.app.tar.gz (geri uyumluluk)
 * CI ayrıca bir şey yapmaz; zamanlayıcı 15 dakikada bir yoklar (yapılandırılmamışsa sessizce çıkar).
 */
class DesktopReleaseSync extends Command
{
    /** Güncelleme arşivleri ve imzaları: universal (eski) + işlemciye özel ince paketler. */
    public const ARCHIVE_PATTERN = '/^ErbaaKurs_[0-9.]+_(universal|aarch64|x86_64)\.app\.tar\.gz(\.sig)?$/';

    /** /desktop/.htaccess izin satırı (kök .htaccess .tar/.gz'yi kapatıyor; yalnız bu dosyalar açılır). */
    public const HTACCESS_ALLOW = '<FilesMatch "^ErbaaKurs_[0-9.]+_(universal|aarch64|x86_64)\.app\.tar\.gz(\.sig)?$">';

    /** 1.12.x öncesi şablonun yalnız universal arşive izin veren satırı (yerinde yükseltilir). */
    private const HTACCESS_ALLOW_OLD = '<FilesMatch "^ErbaaKurs_[0-9.]+_universal\.app\.tar\.gz(\.sig)?$">';

    protected $signature = 'kurs:desktop-release-sync
        {--tag= : belirli bir etiketi çek (ör. desktop-v0.2.0)}
        {--force : aynı sürüm olsa da yeniden indir}
        {--dry-run : yalnız neyin çekileceğini göster}';

    protected $description = 'Masaüstü uygulamasının son sürümünü GitHub Releases\'ten web köküne çeker';

    public function handle(): int
    {
        if (config('kurs.node') === 'local') {
            $this->error('Bu komut yalnız web sunucusunda çalışır.');

            return self::FAILURE;
        }
        $repo = (string) config('desktop.github_repo');
        $token = (string) config('desktop.github_token');
        if ($token === '' || $repo === '') {
            if ($this->getOutput()->isVerbose() || $this->option('tag') || $this->option('dry-run')) {
                $this->warn('DESKTOP_GITHUB_TOKEN / DESKTOP_GITHUB_REPO tanımlı değil; masaüstü sürümleri çekilmiyor.');
            }

            return self::SUCCESS;
        }

        $prefix = (string) config('desktop.tag_prefix', 'desktop-v');
        $gh = fn () => Http::withToken($token)->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'kurs-desktop-release-sync',
        ])->timeout(30)->connectTimeout(10);

        try {
            if ($tag = $this->option('tag')) {
                $release = $gh()->get("https://api.github.com/repos/{$repo}/releases/tags/{$tag}")->throw()->json();
            } else {
                $release = collect($gh()->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 30])->throw()->json())
                    ->filter(fn ($r) => ! ($r['draft'] ?? false) && ! ($r['prerelease'] ?? false) && str_starts_with((string) ($r['tag_name'] ?? ''), $prefix))
                    ->sortByDesc(fn ($r) => $this->versionKey(substr((string) $r['tag_name'], strlen($prefix))))
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Masaüstü sürüm listesi alınamadı', ['error' => $e->getMessage()]);
            $this->error('GitHub sürüm listesi alınamadı: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $release) {
            $this->line('Yayımlanmış masaüstü sürümü yok.');

            return self::SUCCESS;
        }

        $version = substr((string) $release['tag_name'], strlen($prefix));
        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)) {
            $this->error('Etiket biçimi geçersiz: '.$release['tag_name']);

            return self::FAILURE;
        }

        $base = public_path((string) config('desktop.public_dir', 'desktop'));
        $current = is_file("$base/release.json") ? (json_decode((string) file_get_contents("$base/release.json"), true)['version'] ?? null) : null;
        if ($current === $version && ! $this->option('force')) {
            $this->line("Güncel: v{$version}");

            return self::SUCCESS;
        }

        $assets = collect($release['assets'] ?? [])->keyBy('name');
        $manifestAsset = $assets->get('latest.json');
        if (! $manifestAsset) {
            $this->error("v{$version} sürümünde latest.json yok (CI tamamlanmamış olabilir).");

            return self::FAILURE;
        }
        $wanted = $assets->filter(fn ($a, $name) => self::isWantedAsset((string) $name));

        if ($this->option('dry-run')) {
            $this->info("Çekilecek: v{$version} (şu an: ".($current ?? 'yok').')');
            foreach ($wanted as $name => $a) {
                $this->line(sprintf('  %s  %s KB', $name, number_format(($a['size'] ?? 0) / 1024, 0, ',', '.')));
            }

            return self::SUCCESS;
        }

        $dir = "$base/$version";
        $tmp = "$base/.tmp-$version-".bin2hex(random_bytes(4));
        File::ensureDirectoryExists($tmp);

        try {
            $files = [];
            foreach ($wanted as $name => $a) {
                if (! preg_match('/^[A-Za-z0-9._ -]+$/', $name)) {
                    continue;
                }
                $target = "$tmp/$name";
                $res = $gh()->timeout(900)->replaceHeaders(['Accept' => 'application/octet-stream']) // withHeaders birleştirir → GitHub JSON döner
                    ->sink($target)->get("https://api.github.com/repos/{$repo}/releases/assets/{$a['id']}")->throw();
                // Akış kapanmadan son tampon diske yazılmaz; boyut ölçümünden önce kapat
                $res->toPsrResponse()->getBody()->close();
                clearstatcache(true, $target);
                if (isset($a['size']) && filesize($target) !== (int) $a['size']) {
                    throw new \RuntimeException("$name boyutu tutmuyor.");
                }
                $sha = hash_file('sha256', $target);
                if (! empty($a['digest']) && str_starts_with($a['digest'], 'sha256:') && ! hash_equals(substr($a['digest'], 7), $sha)) {
                    throw new \RuntimeException("$name özeti tutmuyor.");
                }
                $files[$name] = ['size' => filesize($target), 'sha256' => $sha];
                $this->line("  indirildi: $name");
            }

            $origin = rtrim((string) config('app.url'), '/');
            $publicUrl = fn (string $name) => $origin.'/'.trim((string) config('desktop.public_dir', 'desktop'), '/').'/'.$version.'/'.rawurlencode($name);

            // Güncelleyici bildirimi: platform url'lerini bu sunucuya çevir
            $manifest = json_decode((string) file_get_contents("$tmp/latest.json"), true);
            if (! is_array($manifest) || empty($manifest['platforms'])) {
                throw new \RuntimeException('latest.json okunamadı.');
            }
            foreach ($manifest['platforms'] as $platform => $p) {
                $name = basename(parse_url((string) ($p['url'] ?? ''), PHP_URL_PATH) ?: '');
                $name = rawurldecode($name);
                if (! isset($files[$name])) {
                    throw new \RuntimeException("latest.json içindeki $platform dosyası ($name) sürümde yok.");
                }
                $manifest['platforms'][$platform]['url'] = $publicUrl($name);
            }

            $dmg = collect($files)->keys()->first(fn ($n) => str_ends_with($n, '.dmg'));
            $changelog = isset($files['CHANGELOG.json']) ? json_decode((string) file_get_contents("$tmp/CHANGELOG.json"), true) : null;
            // İşlemciye göre güncelleme paketi boyutları (/uygulamalar ve denetim için bilgi)
            $updater = [];
            foreach ($manifest['platforms'] as $platform => $p) {
                $name = rawurldecode(basename((string) parse_url((string) $p['url'], PHP_URL_PATH)));
                $updater[$platform] = ['name' => $name, 'size' => $files[$name]['size']];
            }
            $summary = [
                'version' => $version,
                'tag' => $release['tag_name'],
                'pub_date' => $manifest['pub_date'] ?? ($release['published_at'] ?? null),
                'platform' => 'macos',
                'arch' => 'universal',
                'min_os' => $manifest['minimum_system_version'] ?? '12.0',
                'dmg' => $dmg ? ['name' => $dmg, 'url' => $publicUrl($dmg), 'size' => $files[$dmg]['size'], 'sha256' => $files[$dmg]['sha256']] : null,
                'updater' => $updater,
                'notes' => is_array($changelog) ? array_slice($changelog, 0, 5) : null,
                'synced_at' => now()->toIso8601String(),
            ];

            // Yerleştir: önce sürüm klasörü, sonra bildirimler (güncelleyici yarım dosya görmesin)
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
            rename($tmp, $dir);
            // İzin satırı bildirimden ÖNCE: yeni arşiv adları latest.json yayına girdiğinde 403 almasın
            self::ensureHtaccess($base);
            $this->writeAtomic("$base/latest.json", json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->writeAtomic("$base/release.json", json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if (is_array($changelog)) {
                $this->writeAtomic("$base/changelog.json", json_encode($changelog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $this->prune($base, $version);
        } catch (\Throwable $e) {
            File::deleteDirectory($tmp);
            Log::error('Masaüstü sürümü çekilemedi', ['version' => $version, 'error' => $e->getMessage()]);
            $this->error("v{$version} çekilemedi: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Masaüstü v{$version} yayında: {$origin}/".trim((string) config('desktop.public_dir', 'desktop'), '/').'/latest.json');

        return self::SUCCESS;
    }

    private function versionKey(string $v): string
    {
        $core = explode('-', $v, 2)[0];

        return implode('.', array_map(fn ($p) => str_pad((string) (int) $p, 6, '0', STR_PAD_LEFT), array_pad(explode('.', $core), 3, '0')));
    }

    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(3));
        file_put_contents($tmp, $content);
        @chmod($tmp, 0644);
        rename($tmp, $path);
    }

    /** Sürümde çekilecek dosya: DMG, güncelleme arşivleri/imzaları, bildirim ve değişiklik günlüğü. */
    public static function isWantedAsset(string $name): bool
    {
        return str_ends_with($name, '.dmg')
            || preg_match(self::ARCHIVE_PATTERN, $name) === 1
            || in_array($name, ['latest.json', 'CHANGELOG.json'], true);
    }

    /**
     * /desktop/.htaccess: yoksa şablonu yazar; varsa elle yapılmış ayarlara dokunmadan yalnız arşiv izin
     * satırını güncel tutar (eski universal-yalnız satır yerinde değiştirilir, hiç yoksa blok sona eklenir).
     */
    public static function ensureHtaccess(string $base): void
    {
        $file = "$base/.htaccess";
        $allow = self::HTACCESS_ALLOW;
        if (! is_file($file)) {
            file_put_contents($file, <<<HT
# Masaüstü sürümleri (kurs:desktop-release-sync yazar)
Options -Indexes
<FilesMatch "\.(json)$">
    Header set Cache-Control "no-store"
</FilesMatch>
<FilesMatch "\.dmg$">
    ForceType application/x-apple-diskimage
    Header set Content-Disposition "attachment"
</FilesMatch>
<FilesMatch "\.(tar\.gz|sig)$">
    Header set Cache-Control "public, max-age=86400"
</FilesMatch>
# Kök .htaccess arşiv uzantılarını kapatıyor; güncelleyicinin indirdiği paketler burada açık olmalı
# (universal + işlemciye özel aarch64 / x86_64 arşivleri ve imzaları)
$allow
    Require all granted
</FilesMatch>

HT);

            return;
        }
        $content = (string) file_get_contents($file);
        if (str_contains($content, $allow)) {
            return;
        }
        if (str_contains($content, self::HTACCESS_ALLOW_OLD)) {
            $content = str_replace(self::HTACCESS_ALLOW_OLD, $allow, $content);
        } else {
            $content = rtrim($content)."\n# Güncelleme arşivleri (universal + aarch64 / x86_64) ve imzaları\n$allow\n    Require all granted\n</FilesMatch>\n";
        }
        $tmp = $file.'.tmp-'.bin2hex(random_bytes(3));
        file_put_contents($tmp, $content);
        @chmod($tmp, 0644);
        rename($tmp, $file);
    }

    private function prune(string $base, string $keep): void
    {
        $versions = collect(File::directories($base))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($d) => preg_match('/^\d+\.\d+\.\d+/', $d) && $d !== $keep)
            ->sortByDesc(fn ($d) => $this->versionKey($d))
            ->values();
        foreach ($versions->slice(max(0, (int) config('desktop.keep_versions', 2))) as $old) {
            File::deleteDirectory("$base/$old");
        }
        foreach (File::directories($base) as $d) {
            if (str_starts_with(basename($d), '.tmp-') && filemtime($d) < time() - 3600) {
                File::deleteDirectory($d);
            }
        }
    }
}
