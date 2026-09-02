<?php

declare(strict_types=1);

namespace HexaLite\Auth\Attributes;

use Attribute;
use HexaLite\Auth\Guards\PermissionGuard;

/**
 * Exige que el usuario tenga TODOS los permisos indicados.
 *
 *   #[Permission('users:write')]
 *   #[Permission('billing:read', 'billing:write')]
 *
 * Cada argumento admite ALTERNATIVAS separadas por `|`, de las que basta cumplir
 * una — útil para un recurso de referencia al que llegan varios flujos:
 *
 *   #[Permission('ver:vehiculo|ver:cotizacion')]
 *
 * Los permisos son más finos que los roles y sobreviven mejor a los cambios de
 * organigrama: al añadir un rol nuevo no hay que volver a tocar los controladores.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Permission
{
    public string $guardClass;

    /** @var string[] */
    public array $permissions;

    public int $priority;

    public function __construct(string ...$permissions)
    {
        $this->permissions = $permissions;
        $this->priority    = 10;
        $this->guardClass  = PermissionGuard::class;
    }
}
