<?php

declare(strict_types=1);

namespace HexaLite\Examples\Dtos;

use HexaLite\Http\DTO\Dtos;
use HexaLite\Http\DTO\Attributes\IsEmail;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Min;

/**
 * Las reglas se declaran como atributos sobre cada propiedad pública.
 * El Router hidrata y valida el DTO automáticamente cuando lo tipas en la firma
 * de un método de controlador.
 */
final class CreateUserDto extends Dtos
{
    #[IsRequired]
    #[IsString]
    #[Min(2)]
    public string $name;

    #[IsRequired]
    #[IsEmail]
    public string $email;
}
