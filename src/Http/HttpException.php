<?php

declare(strict_types=1);

namespace HexaLite\Http;

use Exception;

/**
 * Excepción HTTP ligera que lleva: status code, error code legible por la UI y detalles opcionales.
 */
class HttpException extends Exception
{
    public ?array $details;

    public function __construct(
        public string $errorCode,
        string $message = '',
        int $status = 400,
        ?array $details = null
    ) {
        parent::__construct($message ?: $errorCode, $status);
        $this->details = $details;
    }

    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }
}
