<?php

namespace App\Controllers;

use App\Libraries\Trio;

/**
 * /api/auth/* — login, logout, me, password.
 */
class Auth extends BaseApi
{
    public function login()
    {
        try {
            [$token, $profile] = $this->tx(fn ($db) => Trio::authLogin($db, $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $r = $this->jsonOut(['ok' => true, 'user' => $profile]);
        $r->setHeader('Set-Cookie',
            Trio::SESSION_COOKIE . '=' . $token . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . Trio::SESSION_TTL);
        return $r;
    }

    public function logout()
    {
        $token = Trio::$session[0] ?? null;
        try {
            $this->tx(fn ($db) => Trio::authLogout($db, $token));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $r = $this->jsonOut(['ok' => true]);
        $r->setHeader('Set-Cookie',
            Trio::SESSION_COOKIE . '=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
        return $r;
    }

    public function me()
    {
        $user = Trio::$session[1] ?? null;
        if (! $user) {
            $seeded = Trio::db()->query('SELECT v FROM meta WHERE k=?', ['seeded'])->getFirstRow('array');
            return $this->jsonOut([
                'ok'             => true,
                'authenticated'  => false,
                'seeded'         => (bool) $seeded,
                'demoAccounts'   => Trio::demoHints(Trio::db()),
            ]);
        }
        return $this->jsonOut([
            'ok'            => true,
            'authenticated' => true,
            'user'          => Trio::publicUser($user),
        ]);
    }

    public function password()
    {
        try {
            $this->tx(fn ($db) => Trio::authChangePassword($db, $this->user(), $this->body()));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        return $this->jsonOut(['ok' => true]);
    }
}
