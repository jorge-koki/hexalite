<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Attributes\NoHtml;
use HexaLite\Http\DTO\Dtos;

/**
 * Edición del propio perfil. Solo el nombre: el correo identifica la cuenta y
 * cambiarlo exige re-verificar, así que va por otro flujo.
 */
final class UpdateProfileDto extends Dtos
{
    #[IsRequired, IsString, Min(2), Max(190), NoHtml]
    public readonly string $name;
}
