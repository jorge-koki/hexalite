<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Application\Dtos;

use HexaLite\Http\DTO\Attributes\IsInt;
use HexaLite\Http\DTO\Attributes\IsRequired;
use HexaLite\Http\DTO\Attributes\IsString;
use HexaLite\Http\DTO\Attributes\Max;
use HexaLite\Http\DTO\Attributes\Min;
use HexaLite\Http\DTO\Attributes\NoHtml;
use HexaLite\Http\DTO\Dtos;

/**
 * El DTO es la frontera de ENTRADA: valida la forma de lo que llega de fuera
 * antes de que roce el dominio. El Router lo hidrata y responde 422 él solo si
 * algo no cumple, así que el caso de uso ya recibe datos con forma correcta.
 *
 * Y ojo con la distinción que más se confunde:
 *
 *   - el DTO valida FORMA      → "titulo es un string de 3 a 150 caracteres"
 *   - el dominio valida REGLAS → "un informe no se publica dos veces"
 *
 * Si metes reglas de negocio aquí, solo se cumplen cuando la petición entra por
 * HTTP. El día que llames al caso de uso desde un cron o desde una cola, la
 * regla no existe. Por eso vive en la entidad, no en el DTO.
 */
final class CrearInformeDto extends Dtos
{
    #[IsRequired]
    #[IsString]
    #[Min(3)]
    #[Max(150)]
    #[NoHtml]
    public string $titulo;

    #[IsRequired]
    #[IsInt]
    #[Min(1)]
    public int $id_proyecto;
}
