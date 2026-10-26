<?php

namespace App\Chat;

use RuntimeException;

class ChatException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 409)
    {
        parent::__construct($message);
    }
}
