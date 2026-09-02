<?php

namespace HexaLite\Http;

use Exception;

class ValidationException extends Exception
{
    public function __construct(public array $errors)
    {
        parent::__construct("Validation Error");
    }
}