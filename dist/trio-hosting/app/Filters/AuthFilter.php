<?php

namespace App\Filters;

use App\Exceptions\ApiException;
use App\Libraries\Trio;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Gerbang /api/* — urutan identik dengan _dispatch_api() server.py:
 * 1. baca+validasi body JSON (server.py parse body SEBELUM auth)
 * 2. init DB + seed (padanan init_db saat server start)
 * 3. resolve sesi cookie trio_session
 * 4. endpoint publik auth (login/logout/me) dilepas tanpa login
 * 5. wajib login -> 401 UNAUTHENTICATED
 * 6. peta izin RBAC -> 403 FORBIDDEN
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        $path = $this->normPath($request->getUri()->getPath());
        if (! str_starts_with($path, '/api/')) {
            return null; // statis / uploads / halaman — lewat begitu saja
        }
        try {
            // 1. body (GET tidak dibaca — persis do_GET server.py)
            Trio::$body = [];
            if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                Trio::$body = Trio::parseBody($request);
            }

            // 2. database + skema + seed
            $db = Trio::ensureInit();

            // 3. sesi
            $token = Trio::cookieToken();
            $userRow = Trio::resolveSession($db, $token);
            Trio::$session = [$token, $userRow];
            Trio::$user = $userRow;

            // 4. endpoint publik auth
            if (Trio::isPublicAuth($method, $path)) {
                return null;
            }

            // 5. wajib login
            if (! $userRow) {
                throw new ApiException(401, 'Belum login atau sesi berakhir. Silakan login ulang.',
                    'UNAUTHENTICATED');
            }

            // 6. izin RBAC server-side
            $need = Trio::permFor($method, $path);
            $role = $userRow['role'];
            if ($need && ! in_array($need, Trio::ROLE_PERMS[$role] ?? [], true)) {
                throw new ApiException(403,
                    'Role ' . (Trio::ROLE_LABEL[$role] ?? $role) . ' tidak memiliki akses: ' . $need,
                    'FORBIDDEN');
            }

            return null;
        } catch (ApiException $e) {
            $r = service('response');
            $r->setStatusCode($e->status);
            $r->setHeader('Content-Type', 'application/json; charset=utf-8');
            $r->setHeader('Cache-Control', 'no-store');
            $r->setHeader('X-Content-Type-Options', 'nosniff');
            $r->setBody(Trio::enc(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->code]));
            return $r;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function normPath(string $path): string
    {
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }
}
