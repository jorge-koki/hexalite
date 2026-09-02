<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Dtos;

/** Cambio de la propia contraseña (con sesión iniciada). */
final class ChangePasswordDto extends Dtos
{
    #[IsRequired, IsString, Max(250)]
    public readonly string $current_password;

    #[IsRequired, IsString, Max(250)]
    public readonly string $new_password;
}
