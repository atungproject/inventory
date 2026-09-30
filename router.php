<?php

/**
 * Router untuk PHP built-in server (lokal & pengujian):
 *
 *     php -S127.0.0.1:8123 router.php       (dari root proyek)
 *
 * Meniru server.py persis:
 *   - OPTIONS            ->204 + Allow
 *   - / , /index.html    -> index.html (no-store)
 *   - /css|js|assets|lib -> file statis dari root proyek (foto: cache, lain: no-store)
 *   - sisanya (/api/*, /uploads/*, path asing) -> CodeIgniter (php/public/index.php)
 *
 * Catatan: /uploads sengaja dilewatkan ke CI4 (controller Files) supaya
 * lokasi file mengikuti env TRIO_UPLOAD_DIR seperti versi Python.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- OPTIONS: persis do_OPTIONS() server.py ----
if ($method === 'OPTIONS') {
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

$path = rawurldecode($path);

const MTIME_STATIC = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico', '.svg'];

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

// ---- / dan /index.html ----
if ($path === '/' || $path === '/index.html') {
    $full = __DIR__ . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($full)) {
        send_file($full, false); // html: no-store (server.py)
    }
    // index.html belum ada -> lanjut ke CI4 (halaman sambutan)
}

// ---- statis: hanya css/ js/ assets/ lib/ (persis _serve_static server.py) ----
$clean = ltrim($path, '/');
$topDir = explode('/', $clean,2)[0] ?? '';
if (in_array($topDir, ['css', 'js', 'assets', 'lib'], true)) {
    $rootReal = realpath(__DIR__);
    $full = realpath(__DIR__ . DIRECTORY_SEPARATOR . $clean);
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

// ---- sisanya: CodeIgniter (/api/*, /uploads/*, path asing ->404 CI4) ----
require __DIR__ . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'public'
    . DIRECTORY_SEPARATOR . 'index.php';
