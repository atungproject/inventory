<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration — SQLite3.
 *
 * Lokasi trio.db:
 *   env TRIO_DATA_DIR          -> dipakai (uji otomatis & fleksibel)
 *   selain itu                 -> sibling docroot: dirname(FCPATH)/data
 *                                 (di luar public_html pada layout produksi)
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    /**
     * @var array<string, mixed>
     */
    public array $default = [];

    /**
     * Dibangun di konstruktor — path DB bergantung FCPATH & env.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => '127.0.0.1',
        'username'    => '',
        'password'    => '',
        'database'    => ':memory:',
        'DBDriver'    => 'SQLite3',
        'DBPrefix'    => 'db_',
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'DBCollat'    => '',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => true,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'synchronous' => null,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        $env = getenv('TRIO_DATA_DIR');
        $dir = $env ? (string) $env : dirname(FCPATH) . DIRECTORY_SEPARATOR . 'data';
        if (! is_dir($dir)) {
            @mkdir($dir,0775, true);
        }

        $this->default = [
            'DSN'         => '',
            'hostname'    => '',
            'username'    => '',
            'password'    => '',
            'database'    => $dir . DIRECTORY_SEPARATOR . 'trio.db',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'pConnect'    => false,
            'DBDebug'     => true,
            'charset'     => 'utf8',
            'DBCollat'    => '',
            'swapPre'     => '',
            'encrypt'     => false,
            'compress'    => false,
            'strictOn'    => false,
            'failover'    => [],
            'port'        => 0,
            'foreignKeys' => false,
            'busyTimeout' => 30000, // tunggu kunci SQLite maksimal 30 detik
            'synchronous' => null,
            'dateFormat'  => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];

        parent::__construct();

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
