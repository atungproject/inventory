<?php

namespace App\Controllers;

use App\Libraries\Trio;

/**
 * Seluruh endpoint data /api/* selain auth & users.
 * Respons tulis selalu {ok, db, user} seperti server.py.
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

    public function settings()
    {
        try {
            $this->tx(fn ($db) => Trio::apiSettings($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->dbState();
    }

    /** GET /api/lists/{key} — server.py tidak memvalidasi key, langsung db state. */
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
            $this->tx(fn ($db) => Trio::seedDemo($db));
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

    /** Fallback persis server.py: "Endpoint tidak ditemukan: METHOD /path". */
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
