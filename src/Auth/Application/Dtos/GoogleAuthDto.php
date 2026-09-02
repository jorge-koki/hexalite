<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Dtos;

/** ID token que devuelve el botón «Continuar con Google» (Google Identity Services). */
final class GoogleAuthDto extends Dtos
{
    #[IsRequired, IsString, Min(32)]
    public readonly string $id_token;
}
