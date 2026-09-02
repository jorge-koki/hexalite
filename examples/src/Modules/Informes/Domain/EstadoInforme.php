<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Domain;

/**
 * Los estados válidos de un informe viven en el dominio: ni en un ENUM de la
 * base de datos, ni en una constante del controlador. Si mañana cambias
 * PostgreSQL por un fichero, esta lista no se toca.
 */
enum EstadoInforme: string
{
    case Borrador  = 'borrador';
    case Publicado = 'publicado';
}
