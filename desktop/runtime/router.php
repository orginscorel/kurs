<?php

/**
 * Erbaa Bilgi Eğitim masaüstü — yerel sunucu yönlendiricisi (`php -S 127.0.0.1:<port> -t <laravel>/public router.php`).
 *
 * Güvenlik (birinci katman; ikinci katman Laravel'deki App\Http\Middleware\DesktopLocalGuard):
 *  - Yalnız 127.0.0.1 / localhost Host başlığı kabul edilir (DNS yeniden bağlama saldırısına karşı).
 *  - Her istek, uygulama başlarken üretilen tek kullanımlık jetonu taşımalıdır: `kurs_desktop` çerezi
 *    (httpOnly, SameSite=Strict) ya da `X-Kurs-Desktop` başlığı. Ortamda yalnız jetonun SHA-256 özeti
 *    (KURS_DESKTOP_TOKEN_HASH) bulunur; jetonun kendisi yalnız masaüstü sürecinin belleğindedir.
 *  - Pencere ilk olarak /__desktop/boot?t=<jeton> adresini açar; burada çerez yazılır ve / adresine yönlenir.
 */

// Yerleşik sunucu yönlendirici betiğinde auto_prepend_file çalıştırmaz → yol sabitlerini burada yükle
require_once __DIR__.'/prepend.php';

$tokenHash = (string) getenv('KURS_DESKTOP_TOKEN_HASH');
$publicPath = (string) (getenv('KURS_PUBLIC_PATH') ?: getcwd());
$uri = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));

$deny = static function (int $status, string $message): bool {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Erişim yok</title>'
        .'<body style="font-family:-apple-system,sans-serif;padding:40px;color:#161b24">'
        .'<h1 style="font-size:18px">Erişim yok</h1><p>'.htmlspecialchars($message).'</p></body>';

    return true;
};

// 1) Host denetimi
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (! preg_match('/^(127\.0\.0\.1|localhost|\[::1\]):\d{2,5}$/', $host)) {
    return $deny(421, 'Bu yerel sunucu yalnız masaüstü uygulamasının penceresinden kullanılabilir.');
}

if ($tokenHash === '' || strlen($tokenHash) !== 64) {
    return $deny(503, 'Yerel sunucu güvenlik anahtarı olmadan başlatılmış. Uygulamayı yeniden açın.');
}

$matches = static fn (?string $token): bool => is_string($token) && $token !== ''
    && hash_equals($tokenHash, hash('sha256', $token));

// 2) Önyükleme: jetonu çereze yaz
if ($uri === '/__desktop/boot') {
    $token = $_GET['t'] ?? null;
    if (! $matches(is_string($token) ? $token : null)) {
        return $deny(403, 'Geçersiz ya da süresi dolmuş açılış bağlantısı.');
    }
    setcookie('kurs_desktop', $token, [
        'expires' => 0,           // oturum çerezi: uygulama kapanınca biter (jeton zaten her açılışta değişir)
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => false,        // yerel http
    ]);
    $next = $_GET['next'] ?? '/';
    if (! is_string($next) || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
        $next = '/';
    }
    header('Cache-Control: no-store');
    header('Location: '.$next, true, 302);

    return true;
}

// 3) Jeton denetimi (statik dosyalar dahil)
$presented = $_COOKIE['kurs_desktop'] ?? ($_SERVER['HTTP_X_KURS_DESKTOP'] ?? null);
if (! $matches(is_string($presented) ? $presented : null)) {
    return $deny(403, 'Bu yerel sunucu yalnız Erbaa Bilgi Eğitim uygulamasının penceresinden kullanılabilir.');
}

// 4) Statik dosyalar (build/, favicon…): yerleşik sunucu verir
if ($uri !== '/' && ! str_contains($uri, '..')) {
    $file = realpath($publicPath.$uri);
    if ($file !== false && is_file($file) && str_starts_with($file, realpath($publicPath).DIRECTORY_SEPARATOR)
        && ! preg_match('/\.php$/i', $file)) {
        return false;
    }
}

// 5) Yüklenen dosyalar: /storage/* → <depolama>/app/public (paket salt okunur, bağlantı yok)
if (str_starts_with($uri, '/storage/') && ! str_contains($uri, '..')) {
    $base = realpath(rtrim((string) getenv('LARAVEL_STORAGE_PATH'), '/').'/app/public');
    $file = $base ? realpath($base.substr($uri, strlen('/storage'))) : false;
    if ($base && $file && is_file($file) && str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
        $type = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
        header('Content-Type: '.$type);
        header('Content-Length: '.filesize($file));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($file);

        return true;
    }
    http_response_code(404);

    return true;
}

// 6) Laravel
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicPath.'/index.php';
