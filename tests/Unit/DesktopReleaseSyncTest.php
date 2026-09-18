<?php

namespace Tests\Unit;

use App\Console\Commands\Desktop\DesktopReleaseSync;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Masaüstü sürüm çekme: işlemciye özel (aarch64 / x86_64) güncelleme arşivleri + /desktop/.htaccess izni. */
class DesktopReleaseSyncTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = sys_get_temp_dir().'/kurs-desktop-sync-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);
        config([
            'kurs.node' => 'server',
            'app.url' => 'https://kurs.example.test',
            'desktop.github_repo' => 'sahip/kurs',
            'desktop.github_token' => 'test-token',
            'desktop.public_dir' => 'desktop',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);
        parent::tearDown();
    }

    public function test_wanted_assets_include_per_arch_archives_and_signatures(): void
    {
        foreach ([
            'ErbaaKurs_1.12.2_universal.dmg',
            'ErbaaKurs_1.12.2_universal.app.tar.gz',
            'ErbaaKurs_1.12.2_universal.app.tar.gz.sig',
            'ErbaaKurs_1.12.2_aarch64.app.tar.gz',
            'ErbaaKurs_1.12.2_aarch64.app.tar.gz.sig',
            'ErbaaKurs_1.12.2_x86_64.app.tar.gz',
            'ErbaaKurs_1.12.2_x86_64.app.tar.gz.sig',
            'latest.json',
            'CHANGELOG.json',
        ] as $name) {
            $this->assertTrue(DesktopReleaseSync::isWantedAsset($name), $name);
        }
        foreach (['SHA256SUMS.txt', 'Erbaa Kurs.app.tar.gz', 'ErbaaKurs_1.12.2_arm64.app.tar.gz', 'ErbaaKurs_1.12.2_x86_64.app.tar.gz.bak'] as $name) {
            $this->assertFalse(DesktopReleaseSync::isWantedAsset($name), $name);
        }
    }

    public function test_htaccess_allow_rule_matches_update_archives_only(): void
    {
        // FilesMatch deseni ile sınıftaki PHP deseni aynı dosyaları seçmeli
        preg_match('/<FilesMatch "(.+)">/', DesktopReleaseSync::HTACCESS_ALLOW, $m);
        $re = '/'.$m[1].'/';
        $this->assertSame(1, preg_match($re, 'ErbaaKurs_1.12.2_aarch64.app.tar.gz'));
        $this->assertSame(1, preg_match($re, 'ErbaaKurs_1.12.2_x86_64.app.tar.gz.sig'));
        $this->assertSame(1, preg_match($re, 'ErbaaKurs_1.12.2_universal.app.tar.gz'));
        $this->assertSame(0, preg_match($re, 'ErbaaKurs_1.12.2_universal.dmg'));
        $this->assertSame(0, preg_match($re, 'backup.tar.gz'));
    }

    public function test_existing_htaccess_with_old_universal_rule_is_upgraded_in_place(): void
    {
        $base = $this->public.'/desktop';
        File::ensureDirectoryExists($base);
        $old = "# elle eklenen ayar\nOptions -Indexes\n<FilesMatch \"^ErbaaKurs_[0-9.]+_universal\\.app\\.tar\\.gz(\\.sig)?$\">\n    Require all granted\n</FilesMatch>\n";
        file_put_contents("$base/.htaccess", $old);

        DesktopReleaseSync::ensureHtaccess($base);
        $new = file_get_contents("$base/.htaccess");
        $this->assertStringContainsString(DesktopReleaseSync::HTACCESS_ALLOW, $new);
        $this->assertStringContainsString('# elle eklenen ayar', $new);
        $this->assertStringNotContainsString('_universal\\.app', $new);
        $this->assertSame(1, substr_count($new, 'Require all granted'));

        // ikinci çağrı değiştirmez
        DesktopReleaseSync::ensureHtaccess($base);
        $this->assertSame($new, file_get_contents("$base/.htaccess"));
    }

    public function test_existing_htaccess_without_rule_gets_block_appended(): void
    {
        $base = $this->public.'/desktop';
        File::ensureDirectoryExists($base);
        file_put_contents("$base/.htaccess", "Options -Indexes\n");
        DesktopReleaseSync::ensureHtaccess($base);
        $new = file_get_contents("$base/.htaccess");
        $this->assertStringStartsWith("Options -Indexes\n", $new);
        $this->assertStringContainsString(DesktopReleaseSync::HTACCESS_ALLOW."\n    Require all granted\n</FilesMatch>", $new);
    }

    public function test_sync_pulls_per_arch_archives_and_rewrites_manifest(): void
    {
        $v = '1.12.2';
        $gh = "https://github.com/sahip/kurs/releases/download/desktop-v$v";
        $sig = fn (string $a) => base64_encode("untrusted comment: signature from tauri secret key\nSIG-$a\n");
        $manifest = [
            'version' => $v,
            'notes' => '[]',
            'pub_date' => '2026-09-18T12:00:00Z',
            'minimum_system_version' => '12.0',
            'platforms' => [
                'darwin-aarch64' => ['signature' => $sig('aarch64'), 'url' => "$gh/ErbaaKurs_{$v}_aarch64.app.tar.gz"],
                'darwin-x86_64' => ['signature' => $sig('x86_64'), 'url' => "$gh/ErbaaKurs_{$v}_x86_64.app.tar.gz"],
                'darwin-universal' => ['signature' => $sig('universal'), 'url' => "$gh/ErbaaKurs_{$v}_universal.app.tar.gz"],
            ],
        ];
        $contents = [
            'latest.json' => json_encode($manifest),
            'CHANGELOG.json' => json_encode([['version' => $v, 'title' => 'x', 'items' => []]]),
            "ErbaaKurs_{$v}_universal.dmg" => 'DMG',
            "ErbaaKurs_{$v}_universal.app.tar.gz" => str_repeat('U', 73),
            "ErbaaKurs_{$v}_universal.app.tar.gz.sig" => $sig('universal'),
            "ErbaaKurs_{$v}_aarch64.app.tar.gz" => str_repeat('A', 40),
            "ErbaaKurs_{$v}_aarch64.app.tar.gz.sig" => $sig('aarch64'),
            "ErbaaKurs_{$v}_x86_64.app.tar.gz" => str_repeat('X', 41),
            "ErbaaKurs_{$v}_x86_64.app.tar.gz.sig" => $sig('x86_64'),
            'SHA256SUMS.txt' => 'yok sayılır',
        ];
        $assets = [];
        $byId = [];
        $id = 100;
        foreach ($contents as $name => $body) {
            $assets[] = ['id' => ++$id, 'name' => $name, 'size' => strlen($body)];
            $byId[$id] = $body;
        }
        Http::fake(function ($request) use ($assets, $byId, $v) {
            $url = $request->url();
            if (preg_match('#/releases/assets/(\d+)$#', $url, $m)) {
                return Http::response($byId[(int) $m[1]]);
            }
            if (str_contains($url, '/releases')) {
                return Http::response([['tag_name' => "desktop-v$v", 'draft' => false, 'prerelease' => false, 'assets' => $assets]]);
            }

            return Http::response('', 404);
        });

        $this->artisan('kurs:desktop-release-sync')->assertExitCode(0);

        $base = $this->public.'/desktop';
        foreach (['aarch64', 'x86_64', 'universal'] as $arch) {
            $this->assertFileExists("$base/$v/ErbaaKurs_{$v}_$arch.app.tar.gz");
            $this->assertFileExists("$base/$v/ErbaaKurs_{$v}_$arch.app.tar.gz.sig");
        }
        $this->assertFileDoesNotExist("$base/$v/SHA256SUMS.txt");

        $latest = json_decode(file_get_contents("$base/latest.json"), true);
        $this->assertSame("https://kurs.example.test/desktop/$v/ErbaaKurs_{$v}_aarch64.app.tar.gz", $latest['platforms']['darwin-aarch64']['url']);
        $this->assertSame("https://kurs.example.test/desktop/$v/ErbaaKurs_{$v}_x86_64.app.tar.gz", $latest['platforms']['darwin-x86_64']['url']);
        $this->assertSame("https://kurs.example.test/desktop/$v/ErbaaKurs_{$v}_universal.app.tar.gz", $latest['platforms']['darwin-universal']['url']);
        $this->assertSame($sig('aarch64'), $latest['platforms']['darwin-aarch64']['signature']);

        $release = json_decode(file_get_contents("$base/release.json"), true);
        $this->assertSame(40, $release['updater']['darwin-aarch64']['size']);
        $this->assertSame(41, $release['updater']['darwin-x86_64']['size']);
        $this->assertSame("ErbaaKurs_{$v}_universal.dmg", $release['dmg']['name']);

        $this->assertStringContainsString(DesktopReleaseSync::HTACCESS_ALLOW, file_get_contents("$base/.htaccess"));
    }
}
