<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Libraries\Trio;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Dasar controller API — respons JSON, state db, dan rollback transaksi.
 */
abstract class BaseApi extends Controller
{
    protected function body(): array
    {
        return Trio::$body;
    }

    protected function user(): array
    {
        return Trio::$user;
    }

    protected function jsonOut($data, int $status =200): ResponseInterface
    {
        $r = service('response');
        $r->setStatusCode($status);
        $r->setHeader('Content-Type', 'application/json; charset=utf-8');
        $r->setHeader('Cache-Control', 'no-store');
        $r->setHeader('X-Content-Type-Options', 'nosniff');
        $r->setBody(Trio::enc($data));
        return $r;
    }

    /**
     * Error keluaran: ApiException -> status+code miliknya; error tak terduga ->
     * {ok:false, error:"Kesalahan server: ..."}500.
     */
    protected function fail(\Throwable $e): ResponseInterface
    {
        if ($e instanceof ApiException) {
            return $this->jsonOut(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->code], $e->status);
        }
        log_message('error', '[TRIO] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $this->jsonOut(['ok' => false, 'error' => 'Kesalahan server: ' . $e->getMessage(), 'code' => null],500);
    }

    /**
     * Satu transaksi per permintaan tulis — commit di akhir, rollback saat error
     * (commit di akhir, rollback saat terjadi error).
     */
    protected function tx(callable $fn)
    {
        $db = Trio::db();
        $db->transBegin();
        try {
            $result = $fn($db);
            $db->transCommit();
            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * Padanan _db_state_response: {ok, db, user} (+extra), user diambil ulang dari DB.
     */
    protected function dbState(int $status =200, array $extra = []): ResponseInterface
    {
        $user = $this->user();
        $out = [
            'ok'   => true,
            'db'   => Trio::buildState(Trio::db(), $user),
            'user' => Trio::publicUser(Trio::userRow((int) $user['id'])),
        ];
        foreach ($extra as $k => $v) {
            $out[$k] = $v;
        }
        return $this->jsonOut($out, $status);
    }
}
