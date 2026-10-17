
<?php

declare(strict_types=1);

$base = realpath(__DIR__ . '/../public/assets');

if ($base === false) {
    http_response_code(500);
    exit('Asset directory unavailable');
}

$file = $_GET['file'] ?? '';

if (!is_string($file) || $file === '') {
    http_response_code(404);
    exit('Asset not found');
}

$path = realpath($base . DIRECTORY_SEPARATOR . $file);

// Permitir apenas ficheiros dentro de public/assets.
if (
    $path === false ||
    !str_starts_with($path, $base . DIRECTORY_SEPARATOR) ||
    !is_file($path)
) {
    http_response_code(404);
    exit('Asset not found');
}

$mimeTypes = [
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'text/javascript; charset=utf-8',
    'svg'   => 'image/svg+xml',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'webp'  => 'image/webp',
    'avif'  => 'image/avif',
    'gif'   => 'image/gif',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
];

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

if (!isset($mimeTypes[$extension])) {
    http_response_code(415);
    exit('Unsupported asset type');
}

header('Content-Type: ' . $mimeTypes[$extension]);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
