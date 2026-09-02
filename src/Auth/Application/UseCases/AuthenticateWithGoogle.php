<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Application\AuthSession;
use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\GoogleIdTokenService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Http\HttpException;

/**
 * «Continuar con Google»: un solo endpoint que entra o da de alta según haga falta.
 *
 * Resolución, en este orden:
 *   1. Por `google_sub` — la vía principal. El correo de una cuenta de Google
 *      puede cambiar; el `sub` no.
 *   2. Por correo — primera vez que ese usuario usa Google. Se enlaza al vuelo
 *      y se da el correo por verificado: Google ya lo comprobó.
 *   3. No existe — se crea la cuenta SIN contraseña y ya verificada.
 *
 * Sin reCAPTCHA a propósito: el reto de Google ya es la barrera anti-bots.
 */
final readonly class AuthenticateWithGoogle
{
    public function __construct(
        private UserRepositoryInterface $users,
        private GoogleIdTokenService $google,
        private TokenService $tokens,
        private AuthConfig $config,
    ) {
    }

    /**
     * @return array{session: AuthSession, created: bool}
     */
    public function execute(string $idToken): array
    {
        $identity = $this->google->verify($idToken);
        $created  = false;

        $user = $this->users->findByGoogleSub($identity['sub']);

        if ($user === null) {
            $user = $this->users->findByEmail($identity['email']);

            if ($user !== null) {
                // Enlace automático por correo. Es seguro porque el correo viene
                // del ID token ya verificado, no de lo que mande el navegador.
                if (!$this->users->linkGoogle((int) $user->id, $identity['sub'], true)) {
                    throw new HttpException(
                        'google_already_linked',
                        'Esa cuenta de Google ya está vinculada a otro usuario.',
                        409
                    );
                }
                $user = $this->users->findById((int) $user->id);
            } else {
                $user = $this->users->create(
                    name:          $identity['name'],
                    email:         $identity['email'],
                    passwordHash:  null,          // solo entra con Google
                    emailVerified: true,          // Google ya certificó el correo
                    googleSub:     $identity['sub'],
                    roles:         $this->config->defaultRoles,
                );
                $created = true;
            }
        }

        if ($user === null) {
            throw new HttpException('google_login_failed', 'No se pudo iniciar sesión con Google.', 500);
        }

        if (!$user->isActive()) {
            throw new HttpException('user_inactive', 'Esta cuenta está deshabilitada.', 403);
        }

        $userId = (int) $user->id;
        $this->users->touchLastLogin($userId);

        return [
            'created' => $created,
            'session' => new AuthSession(
                user:         $user,
                accessToken:  $this->tokens->createAccessToken($userId),
                // Google se usa desde el dispositivo del usuario: sesión persistente.
                refreshToken: $this->tokens->createRefreshToken($userId, true),
                csrfToken:    $this->tokens->createCsrfToken(),
                remember:     true,
                roles:        $this->users->getRoleNames($userId),
                permissions:  $this->users->getEffectivePermissions($userId),
            ),
        ];
    }
}
