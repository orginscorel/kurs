<?php

namespace App\Providers;

use App\Services\Finance\CardPaymentDetails;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\PaymentService;
use App\Sync\BranchResolver;
use App\Sync\ChangeRecorder;
use App\Sync\Http\EnsureSyncDevice;
use App\Sync\Http\RestrictSyncTokens;
use App\Sync\Local\LocalCardPaymentDetails;
use App\Sync\Local\LocalEnrollmentService;
use App\Sync\Local\LocalPaymentService;
use App\Sync\Local\SqliteCompatConnection;
use App\Sync\RowCodec;
use App\Sync\SyncContext;
use App\Sync\SyncSchema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Çevrimdışı yerel kurulum ↔ web eşitlemesi (docs/SYNC.md).
 *  - Değişiklik günlüğü: tüm Eloquent modelleri genel olay dinleyicisiyle (model dosyalarına dokunmadan).
 *  - KURS_NODE=local: finans servisleri komut kaydedici sarmalayıcılarla, SQLite MySQL uyumluluğu,
 *    dış istek yasağı (yalnız eşitleme sunucusu), posta yalnız günlüğe.
 */
class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SyncContext::class);
        $this->app->singleton(SyncSchema::class);
        $this->app->scoped(RowCodec::class);
        $this->app->scoped(ChangeRecorder::class);
        $this->app->scoped(BranchResolver::class);
        // Terminal durum raporu genişletme noktası: terminal katmanı kendi sağlayıcısını bağlar (bind'i ezer)
        $this->app->bindIf(\App\Sync\Contracts\TerminalStatusProvider::class, \App\Sync\Local\NullTerminalStatusProvider::class);

        $local = config('kurs.node') === 'local';

        if ($local || config('database.connections.sqlite.mysql_compat') || config('sync.sqlite_mysql_compat')) {
            Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new SqliteCompatConnection($pdo, $database, $prefix, $config));
        }

        if ($local) {
            // Masaüstü: web arayüzü ve eşitleme döngüsü aynı dosyaya yazar → WAL + bekleme süresi, IMMEDIATE transaction
            config([
                'database.connections.sqlite.busy_timeout' => config('database.connections.sqlite.busy_timeout') ?? 10000,
                'database.connections.sqlite.journal_mode' => config('database.connections.sqlite.journal_mode') ?? 'wal',
                'database.connections.sqlite.synchronous' => config('database.connections.sqlite.synchronous') ?? 'normal',
                'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',   // PHP 8.4+ (8.3'te yok sayılır)
            ]);
            // Önbellek/hız sınırı tablosundaki oku-yaz yükseltmesi SQLite'ta anında "database is locked" verir → dosya önbelleği
            if (config('cache.default') === 'database') {
                config(['cache.default' => 'file']);
            }
            $this->app->bind(PaymentService::class, LocalPaymentService::class);
            $this->app->bind(EnrollmentService::class, LocalEnrollmentService::class);
            $this->app->bind(CardPaymentDetails::class, LocalCardPaymentDetails::class);
            // Finans komutları (docs/SYNC.md › Komut): iade, gelir-gider, aktarım, POS yatışı, senet, tahsilat takibi, ödeme planı
            foreach ([
                \App\Services\Finance\RefundService::class => \App\Sync\Local\LocalRefundService::class,
                \App\Services\Finance\FinanceEntryService::class => \App\Sync\Local\LocalFinanceEntryService::class,
                \App\Services\Finance\AccountService::class => \App\Sync\Local\LocalAccountService::class,
                \App\Services\Finance\ReconciliationService::class => \App\Sync\Local\LocalReconciliationService::class,
                \App\Services\Finance\PromissoryNoteService::class => \App\Sync\Local\LocalPromissoryNoteService::class,
                \App\Services\Finance\CollectionService::class => \App\Sync\Local\LocalCollectionService::class,
                \App\Services\Finance\InstallmentPlanService::class => \App\Sync\Local\LocalInstallmentPlanService::class,
            ] as $abstract => $concrete) {
                $this->app->bind($abstract, $concrete);
            }
            // Parola özeti sunucu-otoriteli: yerelde girişte yeniden özetleme (users yazımı) yapılmaz
            config(['hashing.rehash_on_login' => false]);
        }
    }

    public function boot(): void
    {
        ChangeRecorder::register();

        Event::listen(MigrationsEnded::class, function () {
            app(SyncSchema::class)->flush();
            app(ChangeRecorder::class)->reset();
        });

        $router = $this->app['router'];
        $router->aliasMiddleware('sync.device', EnsureSyncDevice::class);
        $router->pushMiddlewareToGroup('api', RestrictSyncTokens::class);

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(240)
            ->by('sync:'.($request->attributes->get('sync_device')?->id ?: $request->user()?->id ?: $request->ip())));

        if (config('kurs.node') === 'local') {
            $this->bootLocalNode();
        }
    }

    private function bootLocalNode(): void
    {
        // Yerelde dışarıya yalnız eşitleme sunucusuna istek çıkabilir (WhatsApp/SMS/e-posta API'leri kapalı)
        $allowed = [];
        if ($url = config('sync.server_url')) {
            $allowed[] = rtrim((string) $url, '/').'/*';
        }
        try {
            if ($stateUrl = DB::table('sync_state')->where('key', 'server_url')->value('value')) {
                $allowed[] = rtrim((string) $stateUrl, '/').'/*';
            }
            // Kurum veri anahtarı (eşleştirmede mühürlü paketle alındı)
            if (! config('kurs.data_key') && ($enc = DB::table('sync_state')->where('key', 'data_key')->value('value'))) {
                config(['kurs.data_key' => Crypt::decryptString($enc)]);
            }
        } catch (\Throwable) {
            // kurulum/migration öncesi
        }
        Http::preventStrayRequests();
        Http::allowStrayRequests(array_values(array_unique($allowed)));

        config([
            'mail.default' => 'log',
            'kurs.silent_events' => true,
        ]);
    }
}
