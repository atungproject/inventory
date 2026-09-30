<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception API — membawa status HTTP + code JSON.
 */
class ApiException extends RuntimeException
{
    public int $status;
    /** @var string|null kode error API (deklarasi tanpa tipe agar kompatibel Exception::$code) */
    public $code;

    public function __construct(int $status, string $message, ?string $code = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->code   = $code;
    }
}
