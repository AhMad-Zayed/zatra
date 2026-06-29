<?php

namespace App\Exceptions\Auth;

use Exception;

class OtpCoolDownException extends Exception
{
    public int $availableInSeconds;

    public function __construct(int $availableInSeconds, string $message = "Too many failed attempts. Please wait.", int $code = 429, ?\Throwable $previous = null)
    {
        $this->availableInSeconds = $availableInSeconds;
        parent::__construct($message, $code, $previous);
    }
}
