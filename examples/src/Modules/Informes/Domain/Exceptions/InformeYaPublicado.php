<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Domain\Exceptions;

use DomainException;

final class InformeYaPublicado extends DomainException
{
    public static function conId(int $id): self
    {
        return new self("El informe {$id} ya estaba publicado.");
    }
}
