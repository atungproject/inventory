<?php

namespace App\Controllers;

use App\Libraries\Trio;

/**
 * /api/users — manajemen user (izin users.manage sudah dicegah filter).
 */
class Users extends BaseApi
{
    public function index()
    {
        return $this->jsonOut(['ok' => true, 'users' => Trio::usersList(Trio::db())]);
    }

    public function create()
    {
        try {
            $this->tx(fn ($db) => Trio::usersCreate($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->jsonOut(['ok' => true, 'users' => Trio::usersList(Trio::db())]);
    }

    public function update($id)
    {
        try {
            $this->tx(fn ($db) => Trio::usersUpdate($db, $this->user(), (int) $id, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->jsonOut(['ok' => true, 'users' => Trio::usersList(Trio::db())]);
    }

    public function delete($id)
    {
        try {
            $this->tx(fn ($db) => Trio::usersDelete($db, $this->user(), (int) $id));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->jsonOut(['ok' => true, 'users' => Trio::usersList(Trio::db())]);
    }
}
