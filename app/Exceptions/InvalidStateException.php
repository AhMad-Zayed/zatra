<?php

namespace App\Exceptions;

use Exception;

class InvalidStateException extends Exception
{
    public static function transition(string $from, string $to): self
    {
        return new self("Illegal state transition: Cannot move from [{$from}] to [{$to}].");
    }
}
