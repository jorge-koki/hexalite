<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\Confirmed;
use HexaLite\Http\DTO\Attributes\IsEmail;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Attributes\NoHtml;
use HexaLite\Http\DTO\Attributes\Nullable;
use HexaLite\Http\DTO\Dtos;

/**
 * Alta pública. La FUERZA de la contraseña no se valida aquí sino en
 * {@see \HexaLite\Auth\Services\PasswordPolicy}: es configurable por entorno y
 * duplicarla en el DTO haría que las dos reglas acabaran divergiendo.
 */
final class RegisterDto extends Dtos
{
    #[IsRequired, IsString, Min(2, message: 'El nombre es muy corto'), Max(190), NoHtml]
    public readonly string $name;

    #[IsRequired, IsEmail, Max(190)]
    public readonly string $email;

    /** `Confirmed` exige que `password_confirmation` coincida. */
    #[IsRequired, IsString, Max(250), Confirmed(message: 'Las contraseñas no coinciden')]
    public readonly string $password;

    #[IsRequired, IsString]
    public readonly string $password_confirmation;

    #[Nullable, IsString]
    public readonly ?string $recaptcha_token;
}
