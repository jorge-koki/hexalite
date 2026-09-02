<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Domain\Exceptions;

use DomainException;

/**
 * Excepción del dominio: describe QUÉ pasó en el negocio, no cómo se responde
 * por HTTP. Fíjate en que no hay ningún 404 aquí dentro. El mapeo a códigos de
 * estado lo hace el front controller con `registerExceptionHandler()`, así el
 * dominio nunca necesita saber que existe HTTP.
 */
final class InformeNoEncontrado extends DomainException
{
    public static function conId(int $id): self
    {
        return new self("No existe el informe {$id}.");
    }
}
