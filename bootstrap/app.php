<?php

use App\Exceptions\BusinessRuleException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Web paneli aynı alan adından httpOnly oturum çereziyle kimlik doğrular
         * (jeton localStorage'da tutulmaz). Mobil uygulamalar ve donanım köprüsü
         * Bearer jeton kullanır; çerez taşımadıkları için CSRF onlarda atlanır.
         *
         * Oturum ara katmanı API grubuna açıkça eklenir: Sanctum'un statefulApi()
         * Origin/Referer tahmini sunucu-sunucu isteklerinde yanılır. Çerezsiz Bearer isteklerinde
         * oturum başlatılmaz (her mobil çağrıda sessions satırı açılmasın).
         */
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \App\Http\Middleware\StartSessionUnlessBearer::class,
            \App\Http\Middleware\VerifyCsrfUnlessBearer::class,
        ]);

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'device' => \App\Http\Middleware\AuthenticateDevice::class,
            'terminal.desktop' => \App\Http\Middleware\EnsureTerminalDesktop::class,
            'web.only' => \App\Http\Middleware\EnsureWebNode::class,
            'staff' => \App\Http\Middleware\EnsureStaffUser::class,
            'portal.student' => \App\Http\Middleware\EnsurePortalStudent::class,
            'portal.teacher' => \App\Http\Middleware\EnsurePortalTeacher::class,
            'impersonation.readonly' => \App\Http\Middleware\BlockWritesWhileImpersonating::class,
            'password.fresh' => \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);

        // Öğrenci/veli hesabı yönetim uçlarında model bağlamadan (404/403 farkı) önce durdurulur
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureStaffUser::class,
        );

        $middleware->throttleApi();
        $middleware->trustProxies(at: '*');
        // SPA giriş ekranı; Laravel'in varsayılan "login" rotası yok.
        $middleware->redirectGuestsTo('/giris');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Beklenen kullanıcı hataları ERROR olarak loglanmaz (gerçek hataları gölgelemesin):
         * iş kuralı ihlali → INFO (kod + bağlam), doğrulama/yetki/bulunamadı → hiç raporlanmaz.
         */
        $exceptions->report(function (BusinessRuleException $e): bool {
            \Illuminate\Support\Facades\Log::info('İş kuralı: '.$e->getMessage(), [
                'error_code' => $e->errorCode, 'status' => $e->status, 'context' => $e->context,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            return false; // varsayılan (ERROR) raporlamayı durdur
        });
        $exceptions->dontReport([
            ValidationException::class,
            AuthenticationException::class,
            AuthorizationException::class,
            \Spatie\Permission\Exceptions\UnauthorizedException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
        ]);

        /*
         * Tek tip API hata biçimi. Kullanıcı hiçbir zaman teknik ayrıntı görmez;
         * beklenmeyen hatalar kimlikle loga yazılır.
         */
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof BusinessRuleException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => $e->errorCode,
                    'context' => $e->context,
                ], $e->status);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Girilen bilgilerde hata var.',
                    'error_code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Oturumunuz sona ermiş. Lütfen tekrar giriş yapın.',
                    'error_code' => 'unauthenticated',
                ], 401);
            }

            if ($e instanceof TokenMismatchException) {
                return response()->json([
                    'message' => 'Sayfanın süresi doldu. Lütfen sayfayı yenileyin.',
                    'error_code' => 'csrf_mismatch',
                ], 419);
            }

            if ($e instanceof AuthorizationException || $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
                return response()->json([
                    'message' => 'Bu işlem için yetkiniz yok.',
                    'error_code' => 'forbidden',
                ], 403);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'Kayıt bulunamadı.',
                    'error_code' => 'not_found',
                ], 404);
            }

            if ($e instanceof TooManyRequestsHttpException) {
                return response()->json([
                    'message' => 'Çok fazla deneme yapıldı. Lütfen biraz bekleyip tekrar deneyin.',
                    'error_code' => 'too_many_requests',
                ], 429);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => 'İstek işlenemedi.',
                    'error_code' => 'http_error',
                ], $e->getStatusCode());
            }

            $errorId = (string) \Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\Log::error('Beklenmeyen hata', [
                'error_id' => $errorId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
                'trace' => collect($e->getTrace())->take(15)->all(),
            ]);

            return response()->json([
                'message' => 'İşlem sırasında bir sorun oluştu. Lütfen tekrar deneyin.',
                'error_code' => 'server_error',
                'error_id' => $errorId,
            ], 500);
        });
    })
    ->create()
    /*
     * Web kök dizini uygulama dizininin DIŞINDADIR. index.php bu sabiti tanımlar;
     * komut satırında kardeş klasöre düşülür. Vite manifest'i bu yolu kullanır.
     */
    ->usePublicPath(
        defined('KURS_PUBLIC_PATH')
            ? KURS_PUBLIC_PATH
            : dirname(__DIR__, 2).'/kurs.bogahostdeveloper.com.tr',
    );
