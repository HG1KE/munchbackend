<?php

namespace App\Exceptions;

use Exception;

class PalPlussException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
