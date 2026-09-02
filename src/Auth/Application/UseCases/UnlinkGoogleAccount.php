<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Http\HttpException;

/** Desvincula Google de la cuenta. */
final readonly class UnlinkGoogleAccount
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {
    }

    public function execute(int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new HttpException('user_not_found', 'Usuario no encontrado.', 404);
        }

        // Si Google es la ÚNICA forma de entrar, desvincularlo dejaría al usuario
        // fuera de su propia cuenta sin manera de volver.
        if ($user->password === null) {
            throw new HttpException(
                'google_is_only_login',
                'Google es tu única forma de entrar. Crea una contraseña antes de desvincularlo.',
                409
            );
        }

        $this->users->unlinkGoogle($userId);
    }
}
