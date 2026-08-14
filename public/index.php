<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Application.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('session.use_strict_mode', '1');
$persistentSessionLifetime = 10 * 365 * 24 * 60 * 60;
$forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
    ? strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]))
    : '';
$secureSessionCookie = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https';
ini_set('session.gc_maxlifetime', (string) $persistentSessionLifetime);
session_name('pokernote_session');
session_set_cookie_params([
    'lifetime' => $persistentSessionLifetime,
    'path' => '/',
    'secure' => $secureSessionCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// 已登录会话每次访问都会续期；只有主动退出才会清除浏览器中的登录 Cookie。
if (isset($_SESSION['user_id'])) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $persistentSessionLifetime,
        'path' => '/',
        'secure' => $secureSessionCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

try {
    $configuredDatabasePath = getenv('POKERNOTE_DB_PATH');
    $databasePath = is_string($configuredDatabasePath) && $configuredDatabasePath !== ''
        ? $configuredDatabasePath
        : dirname(__DIR__) . '/toolbox.db';
    $database = Database::connect($databasePath);
    $application = new Application($database);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $application->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? rawurldecode($path) : '/');
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    $errorMessage = '数据库初始化失败';
    if (
        strpos($exception->getMessage(), 'pdo_sqlite') !== false
        || strpos($exception->getMessage(), 'could not find driver') !== false
    ) {
        $errorMessage = '数据库初始化失败：PHP 未启用 pdo_sqlite 扩展';
    }
    echo json_encode(['error' => $errorMessage], JSON_UNESCAPED_UNICODE);
}
