<?php

namespace App\Controllers;

use App\Libraries\Trio;

/**
 * Seluruh endpoint data /api/* selain auth & users.
 * Respons tulis selalu {ok, db, user}.
 */
class Api extends BaseApi
{
    public function db()
    {
        return $this->dbState();
    }

    public function upload()
    {
        try {
            $out = Trio::apiUpload($this->body());
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->jsonOut($out);
    }

    public function createAsset()
    {
        try {
            $res = $this->tx(fn ($db) => Trio::apiCreateAsset($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState(201, ['kode' => $res['kode']]);
    }

    public function updateAsset($kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiUpdateAsset($db, $this->user(), (string) $kode, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function createOpname()
    {
        try {
            $res = $this->tx(fn ($db) => Trio::apiCreateOpname($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState(201, ['id' => $res['id']]);
    }

    public function startOpname($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiStartOpname($db, $this->user(), (string) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function countOpnameItem($id, $kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiCountOpname($db, $this->user(), (string) $id, (string) $kode, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function endOpname($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiEndOpname($db, $this->user(), (string) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function cancelOpname($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiCancelOpname($db, $this->user(), (string) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function addDoc($kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiAssetDocs($db, $this->user(), (string) $kode, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function delDoc($kode, $index)
    {
        try {
            $this->tx(fn ($db) => Trio::apiAssetDocs($db, $this->user(), (string) $kode, [], (int) $index));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function createMutation()
    {
        try {
            $res = $this->tx(fn ($db) => Trio::apiCreateMutation($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState(201, ['id' => $res['id']]);
    }

    public function decideMutation($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiDecideMutation($db, $this->user(), (string) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function selesaiMutation($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiSelesaiMutation($db, $this->user(), (string) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function settings()
    {
        try {
            $this->tx(fn ($db) => Trio::apiSettings($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    /** GET /api/lists/{key} — kembalikan state db (key tidak divalidasi). */
    public function listGet()
    {
        return $this->dbState();
    }

    public function listAdd($key)
    {
        try {
            $this->tx(fn ($db) => Trio::apiList($db, $this->user(), (string) $key, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function listDelete($key)
    {
        try {
            $this->tx(fn ($db) => Trio::apiList($db, $this->user(), (string) $key, $this->body(), true));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function logs()
    {
        try {
            $this->tx(fn ($db) => Trio::apiLogs($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function reset()
    {
        try {
            $this->tx(fn ($db) => Trio::resetData($db));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    // ---------------------------------------------------------- hak akses (role)
    public function roleCreate()
    {
        try {
            $this->tx(fn ($db) => Trio::apiRoleCreate($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function roleUpdate($kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiRoleUpdate($db, $this->user(), (string) $kode, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function roleDelete($kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiRoleDelete($db, $this->user(), (string) $kode));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    // ---------------------------------------------------------- master cabang & PIC
    public function cabangCreate()
    {
        try {
            $this->tx(fn ($db) => Trio::apiCabangCreate($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function cabangDelete($kode)
    {
        try {
            $this->tx(fn ($db) => Trio::apiCabangDelete($db, $this->user(), (string) $kode));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function picCreate()
    {
        try {
            $this->tx(fn ($db) => Trio::apiPicCreate($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function picDelete($id)
    {
        try {
            $this->tx(fn ($db) => Trio::apiPicDelete($db, $this->user(), (int) $id));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    public function import()
    {
        try {
            $res = $this->tx(fn ($db) => Trio::apiImport($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState(200, $res);
    }

    /** Fallback: "Endpoint tidak ditemukan: METHOD /path". */
    public function notFound()
    {
        $path = service('request')->getUri()->getPath();
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return $this->jsonOut(['ok' => false, 'error' => "Endpoint tidak ditemukan: {$method} {$path}"],404);
    }
}
