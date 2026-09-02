<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application;

use HexaLite\Auth\Domain\User;

/**
 * Resultado de un login/registro correcto: el usuario y las tres credenciales
 * que el controlador convierte en cookies. Los casos de uso NO tocan HTTP; se
 * limitan a devolver esto.
 */
final readonly class AuthSession
{
    /**
     * @param string[] $roles
     * @param string[] $permissions
     */
    public function __construct(
        public User $user,
        public string $accessToken,
        public string $refreshToken,
        public string $csrfToken,
        public bool $remember = false,
        public array $roles = [],
        public array $permissions = [],
    ) {
    }

    /** Cuerpo JSON estándar de /auth/login y /auth/register. */
    public function toArray(): array
    {
        return [
            'user' => [
                ...$this->user->toArray(),
                'roles'       => $this->roles,
                'permissions' => $this->permissions,
            ],
        ];
    }
}
