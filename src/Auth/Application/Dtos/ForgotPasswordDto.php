<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsEmail;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Nullable;
use HexaLite\Http\DTO\Dtos;

final class ForgotPasswordDto extends Dtos
{
    #[IsRequired, IsEmail, Max(190)]
    public readonly string $email;

    #[Nullable, IsString]
    public readonly ?string $recaptcha_token;
}
