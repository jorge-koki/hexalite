<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\Confirmed;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Dtos;

final class ResetPasswordDto extends Dtos
{
    /** El token del enlace del correo (64 hex = 32 bytes). */
    #[IsRequired, IsString, Min(16), Max(128)]
    public readonly string $token;

    #[IsRequired, IsString, Max(250), Confirmed(message: 'Las contraseñas no coinciden')]
    public readonly string $password;

    /** Obligatorio, igual que en el registro: `Confirmed` compara ambos campos. */
    #[IsRequired, IsString]
    public readonly string $password_confirmation;
}
