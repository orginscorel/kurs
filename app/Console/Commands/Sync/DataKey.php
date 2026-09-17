<?php

namespace App\Console\Commands\Sync;

use App\Support\Sensitive;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kurum veri anahtarı (KURS_DATA_KEY) — tek seferlik geçiş aracı. Ayrıntı: docs/SYNC.md › Güvenlik.
 *
 *   status              anahtar tanımlı mı + her sütunda kaç değer hangi anahtarla şifreli
 *   generate --force    yeni anahtar üretir ve .env'ye yazar (anahtar EKRANA BASILMAZ; yalnız parmak izi)
 *   migrate [--dry-run] APP_KEY ile şifreli değerleri veri anahtarına taşır, TC özetlerini yeniden alır
 *                       (önce veritabanı yedeği; eski değerler sync_key_backups'a). İdempotent.
 *   rollback [--dry-run] [--batch=] taşınan değerleri eski (APP_KEY) hallerine döndürür
 *
 * Kapsam: öğrenci/veli TC (şifreli + HMAC özet), fatura alıcı vergi no, senet borçlu TCKN.
 * Kapsam dışı (APP_KEY'de kalır, cihaza inmez): entegrasyon/webhook sırları, portal başlangıç şifreleri.
 */
class DataKey extends Command
{
    protected $signature = 'kurs:data-key
        {action=status : status|generate|migrate|rollback}
        {--dry-run : yazmadan yalnız say}
        {--force : onay sorma}
        {--no-backup : migrate öncesi veritabanı yedeği alma (önerilmez)}
        {--batch= : rollback için taşıma kimliği (boşsa en sonuncusu)}
        {--chunk=200 : parça boyutu}';

    protected $description = 'Kurum veri anahtarı: üret, şifreli alanları taşı, geri al';

    /** @var list<array{table: string, column: string, hash: ?string, label: string}> */
    public const TARGETS = [
        ['table' => 'students', 'column' => 'national_id_encrypted', 'hash' => 'national_id_hash', 'label' => 'Öğrenci TC'],
        ['table' => 'guardians', 'column' => 'national_id_encrypted', 'hash' => 'national_id_hash', 'label' => 'Veli TC'],
        ['table' => 'invoices', 'column' => 'buyer_tax_id', 'hash' => null, 'label' => 'Fatura alıcı TCKN/VKN'],
        ['table' => 'promissory_notes', 'column' => 'debtor_tax_id', 'hash' => null, 'label' => 'Senet borçlu TCKN'],
    ];

    /** Bilerek APP_KEY'de kalanlar (rapor için) */
    public const EXCLUDED = [
        'integrations.config_encrypted' => 'Entegrasyon sırları (sunucuya özel, cihaza inmez)',
        'webhooks.secret_encrypted' => 'Webhook imza sırları (sunucuya özel)',
        'users.initial_password' => 'Portal başlangıç şifreleri (portal hesapları cihaza inmez)',
    ];

    public function handle(): int
    {
        if (config('kurs.node') === 'local') {
            $this->error('Bu komut yalnız web sunucusunda çalışır; yerel kurulum anahtarı eşleştirmede alır.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'status' => $this->status(),
            'generate' => $this->generate(),
            'migrate' => $this->migrate(),
            'rollback' => $this->rollback(),
            default => $this->invalidAction(),
        };
    }

    // ------------------------------------------------------------------ status

    private function status(): int
    {
        $this->line('Kurum veri anahtarı: '.(Sensitive::hasDataKey() ? 'TANIMLI (parmak izi '.Sensitive::dataKeyFingerprint().')' : 'tanımlı değil (APP_KEY kullanılıyor)'));
        if ($this->sameAsAppKey()) {
            $this->warn('KURS_DATA_KEY, APP_KEY ile aynı: ayrı bir anahtar üretin.');
        }
        $this->table(['Alan', 'Dolu', 'Veri anahtarıyla', 'APP_KEY ile', 'Çözülemeyen', 'Özet güncel değil'], array_map(
            fn ($r) => [$r['label'], $r['total'], $r['data'], $r['app'], $r['unreadable'], $r['hash_stale']],
            $this->scan(false, null),
        ));
        $this->line('Kapsam dışı (APP_KEY): '.implode('; ', array_map(fn ($k, $v) => "$k — $v", array_keys(self::EXCLUDED), self::EXCLUDED)));
        if (Schema::hasTable('sync_key_backups') && ($last = DB::table('sync_key_backups')->orderByDesc('id')->first(['batch', 'migrated_at']))) {
            $this->line(sprintf('Son taşıma: %s (%s), geri alınabilir satır: %d', $last->batch, $last->migrated_at,
                DB::table('sync_key_backups')->where('batch', $last->batch)->whereNull('restored_at')->count()));
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ generate

    private function generate(): int
    {
        if (Sensitive::hasDataKey()) {
            $this->error('KURS_DATA_KEY zaten tanımlı; yeni anahtar üretilmedi (mevcut şifreler bozulmasın).');

            return self::FAILURE;
        }
        $envPath = app()->environmentFilePath();
        $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        if (preg_match('/^KURS_DATA_KEY=\S+/m', $env)) {
            $this->error('KURS_DATA_KEY zaten tanımlı; yeni anahtar üretilmedi (mevcut şifreler bozulmasın).');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('.env dosyasına yeni KURS_DATA_KEY yazılsın mı?')) {
            return self::FAILURE;
        }
        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
        if ($this->option('dry-run')) {
            $this->info('Deneme: anahtar üretilebilir, .env yazılabilir: '.(is_writable($envPath) ? 'evet' : 'HAYIR'));

            return self::SUCCESS;
        }
        $backup = $envPath.'.bak-datakey-'.now()->format('Ymd-His');
        if (! @copy($envPath, $backup)) {
            $this->error('.env yedeği alınamadı; işlem durduruldu.');

            return self::FAILURE;
        }
        @chmod($backup, 0600);
        $line = 'KURS_DATA_KEY='.$key;
        $new = preg_match('/^KURS_DATA_KEY=\s*$/m', $env)
            ? preg_replace('/^KURS_DATA_KEY=\s*$/m', $line, $env)
            : rtrim($env, "\n")."\n\n# Kurum veri anahtarı (TC/vergi no; cihazlara mühürlü paketle gider). SİLMEYİN, DEĞİŞTİRMEYİN.\n".$line."\n";
        file_put_contents($envPath, $new, LOCK_EX);
        config(['kurs.data_key' => $key]);
        $this->info('KURS_DATA_KEY yazıldı (parmak izi '.Sensitive::dataKeyFingerprint().'). .env yedeği: '.basename($backup));
        $this->line('Sonraki adım: php artisan config:clear && php artisan kurs:data-key migrate --dry-run');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ migrate

    private function migrate(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! Sensitive::hasDataKey()) {
            if (! $dry) {
                $this->error('KURS_DATA_KEY tanımlı değil. Önce: php artisan kurs:data-key generate');

                return self::FAILURE;
            }
            // Anahtar henüz yokken deneme: yalnız bu süreçte, bellekte geçici anahtar (hiçbir yere yazılmaz)
            config(['kurs.data_key' => 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')))]);
            $this->line('KURS_DATA_KEY henüz tanımlı değil: deneme bellekteki geçici bir anahtarla yapılıyor.');
        }
        if ($this->sameAsAppKey()) {
            $this->error('KURS_DATA_KEY, APP_KEY ile aynı olamaz.');

            return self::FAILURE;
        }
        if (! $dry && ! Schema::hasTable('sync_key_backups')) {
            $this->error('sync_key_backups tablosu yok: önce migration (2026_09_17_960100) çalıştırılmalı.');

            return self::FAILURE;
        }
        if (! $dry && ! $this->option('no-backup')) {
            $this->line('Veritabanı yedeği alınıyor…');
            if (Artisan::call('kurs:backup', ['--kind' => 'manual']) !== 0) {
                $this->error('Yedek alınamadı; taşıma yapılmadı. '.trim(Artisan::output()));

                return self::FAILURE;
            }
            $this->line(trim(Artisan::output()));
        }
        if (! $dry && ! $this->option('force') && ! $this->confirm('Şifreli alanlar kurum veri anahtarına taşınsın mı?')) {
            return self::FAILURE;
        }

        $batch = $dry ? null : now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $rows = $this->scan(! $dry, $batch);
        $this->table(['Alan', 'Dolu', 'Zaten yeni anahtarda', $dry ? 'Taşınacak' : 'Taşınan', $dry ? 'Özet yenilenecek' : 'Özet yenilenen', 'Çözülemeyen'], array_map(
            fn ($r) => [$r['label'], $r['total'], $r['data'], $r['app'], $r['hash_stale'], $r['unreadable']],
            $rows,
        ));
        $moved = array_sum(array_column($rows, 'app')) + array_sum(array_column($rows, 'hash_stale'));
        if ($dry) {
            $this->info("Deneme: $moved alan değişecek. Hiçbir şey yazılmadı.");
        } elseif ($moved === 0) {
            $this->info('Taşınacak alan kalmadı; hiçbir şey değişmedi.');
        } else {
            $this->info("Taşıma bitti ($batch). Değişen alan: $moved. Geri almak için: php artisan kurs:data-key rollback --batch=$batch");
            if ($moved > 0) {
                \App\Support\Audit::log('sync.data_key_migrated', sprintf('Şifreli kişisel verileri kurum veri anahtarına taşıdı (%d alan, %s).', $moved, $batch));
            }
        }
        if (array_sum(array_column($rows, 'unreadable')) > 0) {
            $this->warn('Çözülemeyen değerler değiştirilmedi (ne APP_KEY ne veri anahtarıyla açılıyor).');
        }

        return self::SUCCESS;
    }

    /**
     * Tüm hedefleri tarar; $apply ise APP_KEY'li değerleri veri anahtarına taşır ve özetleri yeniler.
     *
     * @return list<array{label: string, total: int, data: int, app: int, unreadable: int, hash_stale: int}>
     */
    private function scan(bool $apply, ?string $batch): array
    {
        $out = [];
        foreach (self::TARGETS as $t) {
            $r = ['label' => $t['label'], 'total' => 0, 'data' => 0, 'app' => 0, 'unreadable' => 0, 'hash_stale' => 0];
            if (! Schema::hasTable($t['table']) || ! Schema::hasColumn($t['table'], $t['column'])) {
                $out[] = $r;

                continue;
            }
            $hasUpdated = Schema::hasColumn($t['table'], 'updated_at');
            $cols = array_values(array_filter(['id', $t['column'], $t['hash']]));
            DB::table($t['table'])->whereNotNull($t['column'])->where($t['column'], '!=', '')->select($cols)
                ->chunkById(max(20, (int) $this->option('chunk')), function ($chunk) use ($t, $apply, $batch, $hasUpdated, &$r) {
                    $work = function () use ($chunk, $t, $apply, $batch, $hasUpdated, &$r) {
                        foreach ($chunk as $row) {
                            $r['total']++;
                            $old = (string) $row->{$t['column']};
                            $dec = Sensitive::decryptDetailed($old);
                            if ($dec['value'] === null) {
                                $r['unreadable']++;

                                continue;
                            }
                            $update = [];
                            if ($dec['key'] === 'data') {
                                $r['data']++;
                            } else {
                                $r['app']++;
                                $update[$t['column']] = $apply ? Sensitive::encryptWithDataKey($dec['value']) : '';
                            }
                            $oldHash = $t['hash'] ? $row->{$t['hash']} : null;
                            if ($t['hash'] && $oldHash !== Sensitive::hash($dec['value'])) {
                                $update[$t['hash']] = Sensitive::hash($dec['value']);
                                if (! isset($update[$t['column']])) {
                                    $r['hash_stale']++;
                                }
                            }
                            if (! $apply || $update === []) {
                                continue;
                            }
                            $newValue = $update[$t['column']] ?? $old;
                            DB::table('sync_key_backups')->insert([
                                'batch' => $batch, 'table_name' => $t['table'], 'row_id' => $row->id, 'column_name' => $t['column'],
                                'old_value' => $old, 'old_hash' => $oldHash, 'new_digest' => hash('sha256', $newValue), 'migrated_at' => now(),
                            ]);
                            if ($hasUpdated) {
                                $update['updated_at'] = now();   // süpürücünün hızlı kipi değişikliği görsün → cihazlara iner
                            }
                            DB::table($t['table'])->where('id', $row->id)->update($update);
                        }
                    };
                    $apply ? DB::transaction($work) : $work();
                });
            $out[] = $r;
        }

        return $out;
    }

    // ------------------------------------------------------------------ rollback

    private function rollback(): int
    {
        if (! Schema::hasTable('sync_key_backups')) {
            $this->error('Geri alınacak taşıma yok.');

            return self::FAILURE;
        }
        $batch = $this->option('batch') ?: DB::table('sync_key_backups')->whereNull('restored_at')->orderByDesc('id')->value('batch');
        if (! $batch) {
            $this->info('Geri alınacak taşıma yok.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force') && ! $this->confirm("$batch taşıması geri alınsın mı?")) {
            return self::FAILURE;
        }
        $stats = ['restored' => 0, 'changed_since' => 0];
        DB::table('sync_key_backups')->where('batch', $batch)->whereNull('restored_at')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($dry, &$stats) {
                DB::transaction(function () use ($chunk, $dry, &$stats) {
                    foreach ($chunk as $b) {
                        $target = collect(self::TARGETS)->first(fn ($t) => $t['table'] === $b->table_name && $t['column'] === $b->column_name);
                        $current = DB::table($b->table_name)->where('id', $b->row_id)->first();
                        if (! $target || ! $current || hash('sha256', (string) $current->{$b->column_name}) !== $b->new_digest) {
                            $stats['changed_since']++;   // taşımadan sonra değişmiş/silinmiş: dokunma

                            continue;
                        }
                        $stats['restored']++;
                        if ($dry) {
                            continue;
                        }
                        $update = [$b->column_name => $b->old_value];
                        if ($target['hash']) {
                            $update[$target['hash']] = $b->old_hash;
                        }
                        if (Schema::hasColumn($b->table_name, 'updated_at')) {
                            $update['updated_at'] = now();
                        }
                        DB::table($b->table_name)->where('id', $b->row_id)->update($update);
                        DB::table('sync_key_backups')->where('id', $b->id)->update(['restored_at' => now()]);
                    }
                });
            });
        $this->info(sprintf('%s%s: %d alan eski haline döndü, %d alan taşımadan sonra değiştiği için atlandı.',
            $dry ? 'Deneme ' : '', $batch, $stats['restored'], $stats['changed_since']));
        if (! $dry) {
            $this->warn('Tamamen dönmek için .env\'den KURS_DATA_KEY satırını kaldırıp php artisan config:clear çalıştırın (yeni kayıtlar yine APP_KEY ile şifrelenir).');
            \App\Support\Audit::log('sync.data_key_rolled_back', sprintf('Kurum veri anahtarı taşımasını geri aldı (%s, %d alan).', $batch, $stats['restored']));
        }

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Geçersiz işlem. status | generate | migrate | rollback');

        return self::FAILURE;
    }

    private function sameAsAppKey(): bool
    {
        return Sensitive::hasDataKey() && (string) config('kurs.data_key') === (string) config('app.key');
    }
}
