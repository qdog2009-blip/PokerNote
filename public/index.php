<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if ($path !== '/api' && strpos($path, '/api/') !== 0) {
    $publicDirectory = __DIR__;
    $template = file_get_contents($publicDirectory . '/index.html');
    if ($template === false) {
        http_response_code(500);
        echo '页面模板读取失败';
        exit;
    }

    $assetVersion = static function (string $filePath): string {
        $hash = hash_file('sha256', $filePath);
        if (is_string($hash)) {
            return substr($hash, 0, 12);
        }
        $modifiedAt = filemtime($filePath);
        return $modifiedAt === false ? 'unknown' : (string) $modifiedAt;
    };

    $template = str_replace(
        ['__STYLE_VERSION__', '__APP_VERSION__', '__FONT_VERSION__'],
        [
            $assetVersion($publicDirectory . '/css/style.css'),
            $assetVersion($publicDirectory . '/js/app.js'),
            $assetVersion($publicDirectory . '/fonts/NotoSansSC.ttf'),
        ],
        $template
    );

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $template;
    exit;
}

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Application.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $configuredDatabasePath = getenv('POKERNOTE_DB_PATH');
    $databasePath = is_string($configuredDatabasePath) && $configuredDatabasePath !== ''
        ? $configuredDatabasePath
        : dirname(__DIR__) . '/toolbox.db';
    $database = Database::connect($databasePath);
    $application = new Application($database);
    $application->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
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
