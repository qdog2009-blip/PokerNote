<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if ($path === '/api' || strpos($path, '/api/') === 0 || $path === '/' || $path === '/index.html') {
    require __DIR__ . '/public/index.php';
    return true;
}

return false;
