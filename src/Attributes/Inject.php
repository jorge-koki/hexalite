<?php

declare(strict_types=1);

namespace HexaLite\Attributes;

use Attribute;
use BackedEnum;

/**
 * Atributo para inyectar dependencias por alias (p.ej. una conexión concreta).
 *
 * Acepta un enum respaldado (BackedEnum) o un string. Es AGNÓSTICO a la aplicación:
 * cualquier enum `: string` (o `: int`) de tu app funciona porque implementa
 * BackedEnum; también puedes pasar directamente el identificador string.
 *
 * Uso:
 *
 *   // por enum (se usa su ->value):
 *   public function __construct(
 *       #[Inject(DbConnection::REPLICA)]
 *       private DatabaseInterface $db
 *   ) {}
 *
 *   // o por el identificador string definido en config:
 *   public function __construct(
 *       #[Inject('database.conexion2')]
 *       private DatabaseInterface $db
 *   ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class Inject
{
    public readonly string $alias;

    public function __construct(BackedEnum|string $connection)
    {
        $this->alias = $connection instanceof BackedEnum
            ? (string) $connection->value
            : $connection;
    }
}
