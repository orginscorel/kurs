<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\MysqlCompatSqliteConnection;
use Tests\TestCase;

/**
 * KATI KİP KORUMASI (Model::shouldBeStrict).
 *
 * Canlıda katı kip kapalı: `->get(['id','ad'])`, `select(...)` ya da `with('iliski:id,ad')` ile seçilmemiş bir
 * sütuna erişmek hata vermez, alan sessizce BOŞ (null) döner. Geliştirmede/testte aynı erişim 500 verir.
 * Bu test tüm GET uçlarını (+ zamanlanmış komutları) katı kipte çalıştırır ve yalnız katı kip hatalarını
 * (MissingAttributeException, LazyLoadingViolationException) başarısızlık sayar. Diğer hatalar (doğrulama,
 * SQLite'ın desteklemediği MySQL sözdizimi, örnek verinin anlamsızlığı) bu testin konusu değildir.
 *
 * Veri: gerçek migration'lar + CoreSeeder + her tabloya TÜM sütunları dolu iki örnek satır (FK → 1/2).
 * Bellek içi SQLite; canlı veritabanına ASLA dokunmaz. Her istek geri alınan bir transaction içinde çalışır.
 *
 * Yeni bir uç zorunlu sorgu parametresi istiyorsa QUERY_VARIANTS'a örnek ekleyin.
 */
class StrictModeEndpointsTest extends TestCase
{
    /** Zorunlu parametresi olan uçlar için örnek sorgular. */
    private const QUERY_VARIANTS = [
        'api/v1/schedule/week' => ['?view=class_group&id=1', '?view=teacher&id=1', '?view=classroom&id=1', '?view=student&id=1'],
        'api/v1/schedule/sessions' => ['?view=class_group&id=1', '?view=teacher&id=1', '?view=student&id=1'],
        'api/v1/calendar/events' => ['?from={from}&to={to}', '?from={from}&to={to}&student_id=1', '?from={from}&to={to}&teacher_id=1'],
        'api/v1/schedule/pdf' => ['?view=class_group&id=1', '?view=teacher&id=1'],
        'api/v1/calendar/feeds' => ['?owner_type=teacher&owner_id=1', '?owner_type=class_group&owner_id=1'],
        'api/v1/finance/collections/notes' => ['?student_id=1'],
        'api/v1/finance/documents/receipts.pdf' => ['?ids=1,2'],
        'api/v1/finance/documents/invoices.pdf' => ['?ids=1,2'],
        'api/v1/finance/promissory-notes/pdf' => ['?ids=1,2'],
        'api/v1/finance/accounting/ledger' => ['?code=100', '?code=120', '?code=340', '?code=600'],
        'api/v1/finance/accounting/ledger/export' => ['?code=100'],
        'api/v1/reports/exams/export' => ['?exam_id=1'],
        'api/v1/discipline/incidents/{incident}/notify-draft' => ['?student_id=1&kind=sanction', '?student_id=1&kind=defense'],
    ];

    /** Parametresiz liste uçları ayrıca bu süzgeçle de çağrılır (arama/süzgeç dallarını da gezmek için). */
    private const LIST_FILTER = '?q=a&status=active';

    /** Katı kip hataları: yalnız bunlar testi düşürür. */
    private const STRICT_ERRORS = [
        \Illuminate\Database\Eloquent\MissingAttributeException::class,
        \Illuminate\Database\LazyLoadingViolationException::class,
    ];

    /** Uygulama dışı / sunucuya bağlı uçlar. */
    private const SKIP_PREFIXES = ['api/v1/gateway', 'api/v1/sync', 'api/v1/whatsapp/webhook'];

    private string $storage;

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        Connection::resolverFor('sqlite', fn ($pdo, $db, $prefix, $config) => new MysqlCompatSqliteConnection($pdo, $db, $prefix, $config));
        DB::purge();
        MysqlCompatSqliteConnection::register(DB::connection()->getPdo());

        // Dışa aktarım/PDF/yedek dosyaları geçici dizine; gerçek storage'a yazılmaz.
        $this->storage = sys_get_temp_dir().'/kurs-strict-'.getmypid().'-'.Str::random(6);
        foreach (['app/private', 'app/public', 'framework/cache', 'framework/views', 'logs'] as $dir) {
            File::ensureDirectoryExists($this->storage.'/'.$dir);
        }
        $this->app->useStoragePath($this->storage);
        config(['filesystems.disks.local.root' => $this->storage.'/app/private', 'filesystems.disks.public.root' => $this->storage.'/app/public',
            'kurs.silent_events' => true, 'kurs.node' => 'server']);
        Http::fake();
        Mail::fake();

        // SQLite'ta DATE türü yok: MySQL gibi 'date' dönüşümlü alanları Y-m-d'ye kırp (firstOrCreate eşleşmeleri için).
        Event::listen('eloquent.saving: *', function ($name, $payload) {
            $model = $payload[0];
            $attrs = $model->getAttributes();
            foreach ($model->getCasts() as $key => $cast) {
                if (is_string($cast) && preg_match('/^(immutable_)?date(:|$)/', $cast) && is_string($attrs[$key] ?? null) && strlen($attrs[$key]) > 10) {
                    $attrs[$key] = substr($attrs[$key], 0, 10);
                }
            }
            $model->setRawAttributes($attrs);
        });

        $this->artisan('migrate', ['--force' => true])->run();
        $this->seed(CoreSeeder::class);
        $this->fillEveryTable();
        $this->portalUsers();
    }

    protected function tearDown(): void
    {
        Connection::resolverFor('sqlite', fn ($pdo, $db, $prefix, $config) => new SQLiteConnection($pdo, $db, $prefix, $config));
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_strict_mode_is_on_outside_production(): void
    {
        $this->assertFalse($this->app->isProduction());
        $this->assertTrue(Model::preventsAccessingMissingAttributes(), 'Test/geliştirmede katı kip açık olmalı (AppServiceProvider).');
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_get_endpoints_do_not_touch_unselected_attributes(): void
    {
        $this->withoutExceptionHandling();
        $this->withoutMiddleware([ThrottleRequests::class]);

        $failures = [];
        $ok = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || Str::startsWith($uri, self::SKIP_PREFIXES)) {
                continue;
            }
            $actors = match (true) {
                str_starts_with($uri, 'api/v1/portal/') => ['student', 'guardian'],
                str_starts_with($uri, 'api/v1/teacher-portal/'), str_starts_with($uri, 'api/v1/teacher-panel/') => ['teacher'],
                default => ['admin'],
            };
            $queries = $this->queryVariants($uri) ?? (str_contains($uri, '{') ? [''] : ['', self::LIST_FILTER]);

            foreach ($actors as $actor) {
                foreach ($this->urls($route) as $url) {
                    foreach ($queries as $query) {
                        $error = $this->probe($actor, $url.$query, $status);
                        if ($error !== null && in_array(get_class($error), self::STRICT_ERRORS, true)) {
                            $failures[] = sprintf('[%s] GET %s → %s %s', $actor, $url.$query, class_basename($error), $this->where($error));
                        } elseif ($error === null && $status === 200) {
                            $ok++;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $failures, "Katı kipte seçilmemiş sütun / tembel yükleme hatası veren uçlar:\n".implode("\n", $failures));
        // Tarama gerçekten çalıştı mı? (ör. herkes 403 alırsa test boşa geçmesin)
        $this->assertGreaterThan(250, $ok, 'Başarılı yanıt sayısı beklenenden az; tarama ayarlarını kontrol edin.');
    }

    public function test_scheduled_commands_do_not_touch_unselected_attributes(): void
    {
        config(['kurs.silent_events' => false]);
        $commands = [
            ['kurs:anonymize-withdrawn', ['--dry-run' => true]], ['kurs:attendance-no-show', []], ['kurs:auto-attendance', []],
            ['kurs:campaigns-run', []], ['kurs:campaigns-sync-reports', []], ['kurs:close-homework', []], ['kurs:compute-risk', []],
            ['kurs:daily-digest', ['kind' => 'morning']], ['kurs:daily-digest', ['kind' => 'evening']], ['kurs:discipline-sweep', []],
            ['kurs:homework-due-tomorrow-reminders', []], ['kurs:homework-missed-notify', []], ['kurs:installment-reminders', []],
            ['kurs:lead-next-action-reminders', []], ['kurs:lesson-starting-reminders', []], ['kurs:mark-overdue', []],
            ['kurs:overdue-installment-webhooks', []], ['kurs:prune', ['--dry-run' => true]], ['kurs:remind-tasks', []],
            ['kurs:schedule-tomorrow', []], ['kurs:student-accounts', ['--dry-run' => true]], ['kurs:guardian-accounts', ['--dry-run' => true]],
            ['kurs:accounting-backfill', ['--dry-run' => true]], ['kurs:sync-recompute', []],
        ];

        $failures = [];
        foreach ($commands as [$command, $args]) {
            DB::beginTransaction();
            try {
                Artisan::call($command, $args);
            } catch (\Throwable $e) {
                if (in_array(get_class($e), self::STRICT_ERRORS, true)) {
                    $failures[] = sprintf('%s %s → %s %s', $command, json_encode($args), class_basename($e), $this->where($e));
                }
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        }

        $this->assertSame([], $failures, "Katı kipte hata veren komutlar:\n".implode("\n", $failures));
    }

    // ------------------------------------------------------------------ yardımcılar

    private function probe(string $actor, string $url, ?int &$status): ?\Throwable
    {
        $status = null;
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->users[$actor], ['*']);
        DB::beginTransaction();
        try {
            // Bearer başlığı: VerifyCsrfUnlessBearer oturumsuz istekte CSRF denetimini atlar.
            $response = $this->withHeader('Authorization', 'Bearer strict-scan')->getJson($url);
            $status = $response->getStatusCode();
            if ($response->baseResponse instanceof StreamedResponse) {
                ob_start();
                try {
                    $response->baseResponse->sendContent(); // Excel/CSV gövdesi ancak gönderilirken üretilir
                } finally {
                    ob_end_clean();
                }
            }

            return null;
        } catch (\Throwable $e) {
            return $e;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function where(\Throwable $e): string
    {
        $frame = collect($e->getTrace())->first(fn ($f) => isset($f['file']) && str_starts_with($f['file'], app_path()));

        return trim($e->getMessage().($frame ? ' @ '.Str::after($frame['file'], base_path().'/').':'.$frame['line'] : ''));
    }

    private function queryVariants(string $uri): ?array
    {
        $variants = self::QUERY_VARIANTS[$uri] ?? null;

        return $variants === null ? null : array_map(fn ($q) => strtr($q, [
            '{from}' => now()->subDays(30)->toDateString(), '{to}' => now()->addDays(20)->toDateString(),
        ]), $variants);
    }

    /** Rota parametrelerini doldurur: model bağlaması → ilk kayıt; bilinen metin parametreleri → tüm değerler. */
    private function urls(RoutingRoute $route): array
    {
        $typed = [];
        foreach ($route->signatureParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                $typed[$p->getName()] = $type->getName();
            }
        }

        $urls = [$route->uri()];
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
        foreach ($m[1] as $name) {
            $values = isset($typed[$name]) ? [$this->firstId($typed[$name])] : match ($name) {
                'key' => array_keys(\App\Services\Accounting\FinanceAnalytics::REPORTS),
                'entity' => ['students', 'teachers'],
                'group' => array_keys((new \ReflectionClassConstant(\App\Http\Controllers\Api\Settings\InstitutionController::class, 'GROUP_RULES'))->getValue()),
                default => ['1'],
            };
            $next = [];
            foreach ($urls as $url) {
                foreach ($values as $value) {
                    $next[] = preg_replace('/\{'.$name.'\??\}/', (string) $value, $url, 1);
                }
            }
            $urls = $next;
        }

        return array_map(fn ($u) => rtrim($u, '/'), $urls);
    }

    private function firstId(string $class): string
    {
        $query = $class::query()->withoutGlobalScopes();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn());
        }

        return (string) ($query->orderBy($query->getModel()->getKeyName())->value($query->getModel()->getKeyName()) ?? 1);
    }

    /**
     * Boş her tabloya iki satır: tüm sütunlar dolu (seçilmemiş sütun erişimi ancak değer varken anlam taşır).
     * Değerler modelin dönüşümlerinden (cast) ve sabitlerinden (STATUSES, KINDS…) türetilir.
     */
    private function fillEveryTable(): void
    {
        $models = [];
        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $models[(new $class)->getTable()] ??= new $class;
            }
        }

        $skip = ['migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'];
        $leaveNull = '/^(deleted_at|.*voided_at|cancelled_at|archived_at|revoked_at|expires_at|left_on|valid_until|merged_into_id|replaced_by_id|reversal_of_id|parent_id|related_invoice_id|superseded_by.*)$/';
        $tables = array_map(fn ($t) => $t->name, DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"));

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $table) {
            if (in_array($table, $skip, true) || DB::table($table)->exists()) {
                continue;
            }
            $model = $models[$table] ?? null;
            $casts = $model?->getCasts() ?? [];
            foreach ([1, 2] as $n) {
                $row = [];
                foreach (Schema::getColumns($table) as $col) {
                    $name = $col['name'];
                    if ($col['auto_increment']) {
                        $row[$name] = $n;
                    } elseif ($col['nullable'] && preg_match($leaveNull, $name)) {
                        continue;
                    } else {
                        $row[$name] = $this->sampleValue($table, $col, $casts[$name] ?? null, $model, $n);
                    }
                }
                try {
                    DB::table($table)->insert($row);
                } catch (\Throwable) {
                    // benzersizlik vb. — örnek satır atlanır
                }
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    private function sampleValue(string $table, array $col, mixed $cast, ?Model $model, int $n): mixed
    {
        $name = $col['name'];
        $type = strtolower((string) ($col['type_name'] ?? $col['type']));
        $castName = is_string($cast) ? strtolower(Str::before($cast, ':')) : '';

        if ($name === 'branch_id') {
            return 1;
        }
        if ($name === 'uuid') {
            return (string) Str::uuid();
        }
        if ($model && ($constant = $this->constantKey($model, $name)) !== null) {
            return $constant;
        }
        if (is_string($cast) && class_exists($cast) && enum_exists($cast)) {
            return $cast::cases()[0]->value ?? $cast::cases()[0]->name;
        }
        if (str_starts_with($castName, 'encrypted')) {
            return Crypt::encryptString($castName === 'encrypted' ? 'Örnek '.$n : '[]');
        }
        if (in_array($castName, ['array', 'json', 'collection', 'object'], true) || (is_string($cast) && str_contains($cast, 'AsArrayObject')) || (is_string($cast) && str_contains($cast, 'AsCollection'))) {
            return $castName === 'object' ? '{}' : '[]';
        }
        if (str_ends_with($name, '_type')) {
            return 'student';
        }
        if ($name === 'id' || str_ends_with($name, '_id')) {
            return $n;
        }

        return match (true) {
            in_array($castName, ['bool', 'boolean'], true), str_contains($type, 'bool'), $type === 'tinyint' => 1,
            in_array($castName, ['date', 'immutable_date'], true), $type === 'date' => now()->subDays($n)->toDateString(),
            in_array($castName, ['datetime', 'immutable_datetime', 'timestamp'], true), str_contains($type, 'datetime'), str_contains($type, 'timestamp') => now()->subHours($n)->format('Y-m-d H:i:s'),
            $type === 'time' => sprintf('%02d:00:00', 9 + $n),
            in_array($castName, ['int', 'integer'], true), str_contains($type, 'int') => $n,
            str_starts_with($castName, 'decimal'), in_array($castName, ['float', 'double', 'real'], true),
            str_contains($type, 'numeric'), str_contains($type, 'decimal'), str_contains($type, 'real'), str_contains($type, 'float'), str_contains($type, 'double') => '100.00',
            str_contains($type, 'json') => '[]',
            $col['default'] !== null => trim((string) $col['default'], "'\""),
            str_contains($name, 'phone') => '0532000000'.$n,
            str_contains($name, 'email') => "ornek{$n}@ornek.test",
            default => Str::limit("Örnek {$name} {$n}", 60, ''),
        };
    }

    private function constantKey(Model $model, string $column): mixed
    {
        $class = $model::class;
        $candidates = array_unique([strtoupper(Str::plural($column)), strtoupper($column).'S', strtoupper($column).'ES']);
        foreach ($candidates as $constant) {
            if (defined("$class::$constant")) {
                $value = constant("$class::$constant");
                if (is_array($value) && $value !== []) {
                    return array_is_list($value) ? $value[0] : array_key_first($value);
                }
            }
        }

        return null;
    }

    /** Portal hesapları: öğrenci #1, veli #1 (öğrenci #1'in velisi), öğretmen #1. */
    private function portalUsers(): void
    {
        $this->users['admin'] = User::query()->where('username', 'admin')->firstOrFail();

        foreach (['student' => ['ogrenci', 'students'], 'guardian' => ['veli', 'guardians'], 'teacher' => ['ogretmen', 'teachers']] as $type => [$role, $table]) {
            $user = new User;
            $user->forceFill([
                'branch_id' => 1, 'name' => 'Tarama '.$type, 'username' => 'tarama.'.$type, 'user_type' => $type,
                'password' => 'Tarama2026!', 'is_active' => true, 'must_change_password' => false,
                'initial_password' => null, 'password_changed_at' => now(),
            ])->save();
            $user->refresh(); // tüm sütunlar (gerçek oturumdaki gibi)
            $user->assignRole($role);
            DB::table($table)->update(['user_id' => null]);
            DB::table($table)->where('id', 1)->update(['user_id' => $user->id]);
            $this->users[$type] = $user;
        }

        DB::table('students')->update(['status' => 'active']);
        DB::table('guardian_student')->where('guardian_id', 1)->update(['student_id' => 1]);
        DB::table('teachers')->update(['is_active' => true]);
    }
}
