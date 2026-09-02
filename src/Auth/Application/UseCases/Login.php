<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Application\AuthSession;
use HexaLite\Auth\Application\Dtos\LoginDto;
use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\RecaptchaService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Http\HttpException;
use Throwable;

/**
 * Inicio de sesión con correo y contraseña.
 *
 * Todo el diseño gira alrededor de NO filtrar qué correos están registrados:
 * mismo código, mismo mensaje y —gracias al hash de descarte— tiempos de
 * respuesta parecidos tanto si la cuenta no existe como si la contraseña es
 * incorrecta.
 */
final readonly class Login
{
    /**
     * Hash bcrypt de descarte para igualar el tiempo de respuesta cuando el
     * correo NO existe. Sin esto, la ausencia de `password_verify` hace que la
     * respuesta vuelva antes y eso, medido, delata las cuentas registradas.
     * No corresponde a ninguna contraseña real.
     */
    private const DUMMY_HASH = '$2y$12$E9e8/ITEDechFZKFGLrefu3Hmykg1IRl3JxkjlgfKrN43rM4a2YmC';

    public function __construct(
        private UserRepositoryInterface $users,
        private TokenService $tokens,
        private AuthConfig $config,
        private ?RecaptchaService $recaptcha = null,
    ) {
    }

    public function execute(LoginDto $input, ?string $clientIp = null): AuthSession
    {
        $this->recaptcha?->verify($input->recaptcha_token, $clientIp);

        $user = $this->users->findByEmail($input->email);

        if ($user === null) {
            password_verify($input->password, self::DUMMY_HASH);
            throw self::invalidCredentials();
        }

        if (!$user->verifyPassword($input->password)) {
            throw self::invalidCredentials();
        }

        if (!$user->isActive()) {
            throw new HttpException('user_inactive', 'Esta cuenta está deshabilitada.', 403);
        }

        // Verificación DURA (opt-in). Por defecto la verificación es BLANDA: el
        // usuario entra igual y el front le muestra un aviso — obligar a verificar
        // antes de poder entrar pierde usuarios por correos que tardan o se pierden.
        if ($this->config->requireVerifiedEmail && !$user->hasVerifiedEmail()) {
            throw new HttpException(
                'email_not_verified',
                'Verifica tu correo antes de iniciar sesión.',
                403
            );
        }

        $userId = (int) $user->id;

        // Best-effort: el sello de "último acceso" jamás debe tumbar un login.
        try {
            $this->users->touchLastLogin($userId);
        } catch (Throwable $e) {
            error_log('[auth] touchLastLogin falló: ' . $e->getMessage());
        }

        $remember = $input->remember_me ?? false;

        return new AuthSession(
            user:         $user,
            accessToken:  $this->tokens->createAccessToken($userId),
            refreshToken: $this->tokens->createRefreshToken($userId, $remember),
            csrfToken:    $this->tokens->createCsrfToken(),
            remember:     $remember,
            roles:        $this->users->getRoleNames($userId),
            permissions:  $this->users->getEffectivePermissions($userId),
        );
    }

    /** Mismo error para "no existe" y "contraseña mala": no se delata la cuenta. */
    private static function invalidCredentials(): HttpException
    {
        return new HttpException('invalid_credentials', 'Correo o contraseña incorrectos.', 401);
    }
}
