<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Dtos;
use HexaLite\Http\DTO\Attributes\IsBoolean;
use HexaLite\Http\DTO\Attributes\IsEmail;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Nullable;

final class LoginDto extends Dtos
{
    #[IsRequired, IsEmail, Max(190)]
    public readonly string $email;

    /**
     * Sin `Min` a propósito: aquí solo se comprueba la credencial contra el hash.
     * Exigir longitud mínima al ENTRAR delataría la política a un atacante y
     * daría un error distinto —"muy corta"— al de una contraseña incorrecta.
     */
    #[IsRequired, IsString, Max(250)]
    public readonly string $password;

    /** "Recordarme": alarga la sesión a 30 días en vez de cerrarla con el navegador. */
    #[Nullable, IsBoolean]
    public readonly ?bool $remember_me;

    /** Solo se verifica si RECAPTCHA_SECRET está configurado. */
    #[Nullable, IsString]
    public readonly ?string $recaptcha_token;
}
