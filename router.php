<?php

/**
 * Router untuk PHP built-in server (lokal, VPS, maupun Docker).
 *
 *     php -S 0.0.0.0:8123 router.php
 *
 * Meniru pengaturan Apache + .htaccess pada shared hosting:
 *   - OPTIONS            -> 204 + Allow
 *   - / , /index.html    -> index.html (tanpa cache)
 *   - /css|js|assets|lib -> berkas statis dari public_html (gambar: cache)
 *   - berkas top-level   -> favicon.ico, robots.txt, dll.
 *   - sisanya (/api/*, /uploads/*, path asing) -> front controller public_html/index.php
 *
 * public_html = docroot; data/ & uploads/ tetap di induk docroot
 * sehingga database dan lampiran tidak terekspos ke web.
 */

$DOCROOT = __DIR__ . DIRECTORY_SEPARATOR . 'public_html';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

$path = rawurldecode($path);

function send_file(string $full, bool $cache): void
{
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mime = [
        'html' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript',
        'mjs' => 'text/javascript', 'json' => 'application/json', 'txt' => 'text/plain',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf', 'xml' => 'application/xml',
    ][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: ' . ($cache ? 'public, max-age=86400' : 'no-store'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($full));
    readfile($full);
    exit;
}

if ($path === '/' || $path === '/index.html') {
    $full = $DOCROOT . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($full)) {
        send_file($full, false);
    }
}

$clean = ltrim($path, '/');
$topDir = explode('/', $clean, 2)[0] ?? '';
if (in_array($topDir, ['css', 'js', 'assets', 'lib'], true)) {
    $rootReal = realpath($DOCROOT);
    $full = realpath($DOCROOT . DIRECTORY_SEPARATOR . $clean);
    if ($full !== false && is_file($full)
        && str_starts_with($full, $rootReal . DIRECTORY_SEPARATOR . $topDir . DIRECTORY_SEPARATOR)) {
        send_file($full, in_array($ext = strtolower(pathinfo($full, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'], true));
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

if ($clean !== '' && ! str_contains($clean, '/')) {
    $full = realpath($DOCROOT . DIRECTORY_SEPARATOR . $clean);
    if ($full !== false && is_file($full)
        && str_starts_with($full, realpath($DOCROOT) . DIRECTORY_SEPARATOR)
        && strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'php') {
        send_file($full, in_array(strtolower(pathinfo($full, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'], true));
    }
}

require $DOCROOT . DIRECTORY_SEPARATOR . 'index.php';
