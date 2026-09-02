<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\GoogleIdTokenService;
use HexaLite\Http\HttpException;

/**
 * Vincula una cuenta de Google al usuario que YA tiene la sesión iniciada.
 * Cubre el caso que el enlace automático por correo no alcanza: que el correo
 * de la cuenta y el de Google sean distintos.
 */
final readonly class LinkGoogleAccount
{
    public function __construct(
        private UserRepositoryInterface $users,
        private GoogleIdTokenService $google,
    ) {
    }

    /** @return array{email: string} Correo de Google que quedó vinculado. */
    public function execute(int $userId, string $idToken): array
    {
        $identity = $this->google->verify($idToken);

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new HttpException('user_not_found', 'Usuario no encontrado.', 404);
        }

        // El correo solo se da por verificado si es EL MISMO: Google certificó
        // ese, no el que el usuario tenga registrado en la app.
        $sameEmail = strcasecmp($user->email, $identity['email']) === 0;

        if (!$this->users->linkGoogle($userId, $identity['sub'], $sameEmail)) {
            throw new HttpException(
                'google_already_linked',
                'Esa cuenta de Google ya está vinculada a otro usuario.',
                409
            );
        }

        return ['email' => $identity['email']];
    }
}
