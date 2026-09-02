<?php

declare(strict_types=1);

namespace HexaLite\Auth\Attributes;

use Attribute;
use HexaLite\Auth\Guards\RoleGuard;

/**
 * Exige que el usuario tenga AL MENOS UNO de los roles indicados.
 *
 *   #[Roles('admin', 'owner')]
 *
 * Requiere que un middleware de auth haya publicado ya al usuario en la
 * petición: sin sesión, el guard responde 401 (no 403).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Roles
{
    public string $guardClass;

    /** @var string[] */
    public array $roles;

    public int $priority;

    public function __construct(string ...$roles)
    {
        $this->roles      = $roles;
        $this->priority   = 10;
        $this->guardClass = RoleGuard::class;
    }
}
