<?php

namespace App\Controllers;

use App\Libraries\Trio;
use CodeIgniter\Controller;

/**
 * GET /uploads/{nama} — menyajikan file unggahan tanpa login.
 * File disimpan di luar docroot via TRIO_UPLOAD_DIR.
 */
class Files extends Controller
{
    private const MIME = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'pdf' => 'application/pdf', 'txt' => 'text/plain',
    ];

    public function serve($fname)
    {
        $fname = (string) $fname;
        if ($fname === '' || str_contains($fname, '/') || str_contains($fname, '\\')
            || str_contains($fname, '..')) {
            return $this->notFound();
        }
        $base = realpath(Trio::uploadDir());
        if ($base === false) {
            return $this->notFound();
        }
        $full = realpath($base . DIRECTORY_SEPARATOR . $fname);
        if ($full === false || ! is_file($full)
            || ! str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $r = service('response');
        $r->setStatusCode(200);
        $r->setHeader('Content-Type', self::MIME[$ext] ?? 'application/octet-stream');
        $r->setHeader('Cache-Control', 'public, max-age=86400');
        $r->setHeader('X-Content-Type-Options', 'nosniff');
        $r->setBody((string) file_get_contents($full));
        return $r;
    }

    private function notFound()
    {
        $r = service('response');
        $r->setStatusCode(404);
        $r->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $r->setBody('Not Found');
        return $r;
    }
}
