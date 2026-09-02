<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Dtos;

/** Token del enlace «verifica tu correo». */
final class VerifyEmailDto extends Dtos
{
    #[IsRequired, IsString, Min(16), Max(128)]
    public readonly string $token;
}
