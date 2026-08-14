<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if ($path === '/api' || strpos($path, '/api/') === 0) {
    require __DIR__ . '/public/index.php';
    return true;
}

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    return true;
}

return false;
