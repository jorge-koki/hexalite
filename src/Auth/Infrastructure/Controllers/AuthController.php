<?php

declare(strict_types=1);

namespace HexaLite\Auth\Infrastructure\Controllers;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Middleware;
use HexaLite\Attributes\Route;
use HexaLite\Auth\Application\AuthSession;
use HexaLite\Auth\Application\Dtos\ChangePasswordDto;
use HexaLite\Auth\Application\Dtos\ForgotPasswordDto;
use HexaLite\Auth\Application\Dtos\GoogleAuthDto;
use HexaLite\Auth\Application\Dtos\LoginDto;
use HexaLite\Auth\Application\Dtos\RegisterDto;
use HexaLite\Auth\Application\Dtos\ResetPasswordDto;
use HexaLite\Auth\Application\Dtos\UpdateProfileDto;
use HexaLite\Auth\Application\Dtos\VerifyEmailDto;
use HexaLite\Auth\Application\UseCases\AuthenticateWithGoogle;
use HexaLite\Auth\Application\UseCases\ChangePassword;
use HexaLite\Auth\Application\UseCases\LinkGoogleAccount;
use HexaLite\Auth\Application\UseCases\Login;
use HexaLite\Auth\Application\UseCases\Register;
use HexaLite\Auth\Application\UseCases\RequestPasswordReset;
use HexaLite\Auth\Application\UseCases\ResendVerification;
use HexaLite\Auth\Application\UseCases\ResetPassword;
use HexaLite\Auth\Application\UseCases\UnlinkGoogleAccount;
use HexaLite\Auth\Application\UseCases\VerifyEmail;
use HexaLite\Auth\Attributes\Throttle;
use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Middlewares\AuthMiddleware;
use HexaLite\Auth\Middlewares\CsrfMiddleware;
use HexaLite\Auth\Services\GoogleIdTokenService;
use HexaLite\Auth\Services\JwtPayload;
use HexaLite\Auth\Services\SessionCookies;
use HexaLite\Auth\Services\TokenRevocationService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Http\HttpException;
use HexaLite\Http\Request;
use HexaLite\Http\Response;
use Throwable;

/**
 * Todos los endpoints de autenticación del kit.
 *
 * Contrato con el frontend (detalle completo en `docs/FRONTEND.md`):
 *   • La sesión viaja en cookies HttpOnly, así que el front SIEMPRE debe llamar
 *     con `credentials: 'include'`.
 *   • Las peticiones de escritura con sesión iniciada tienen que reenviar la
 *     cookie `csrf_token` en la cabecera `X-CSRF-TOKEN`.
 *   • Nadie tiene que gestionar el refresco: {@see AuthMiddleware} renueva el
 *     access token de forma transparente mientras el refresh siga vivo.
 */
#[Controller('/auth')]
final class AuthController
{
    public function __construct(
        private readonly Login $login,
        private readonly Register $register,
        private readonly VerifyEmail $verifyEmail,
        private readonly ResendVerification $resendVerification,
        private readonly RequestPasswordReset $requestPasswordReset,
        private readonly ResetPassword $resetPassword,
        private readonly ChangePassword $changePassword,
        private readonly AuthenticateWithGoogle $googleAuth,
        private readonly LinkGoogleAccount $linkGoogle,
        private readonly UnlinkGoogleAccount $unlinkGoogle,
        private readonly UserRepositoryInterface $users,
        private readonly TokenService $tokens,
        private readonly TokenRevocationService $revocation,
        private readonly SessionCookies $cookies,
        private readonly GoogleIdTokenService $google,
        private readonly AuthConfig $config,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALTA E INICIO DE SESIÓN
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/register', 'POST')]
    #[Throttle(5, 60)]
    public function registerUser(RegisterDto $input, Request $request): Response
    {
        $result  = $this->register->execute($input, $request->getIp());
        $session = $result['session'];

        $body = $session->toArray() + ['message' => 'registered'];

        // Fuera de producción se devuelve el enlace de verificación para poder
        // probar el flujo completo sin un proveedor de correo configurado. En
        // producción JAMÁS: es una credencial de un solo uso.
        if (!$this->config->isProduction) {
            $body['verification_url'] = $result['verification_url'];
        }

        return $this->withSession(response()->json($body, 201), $session);
    }

    #[Route('/login', 'POST')]
    #[Throttle(10, 60)]
    public function loginUser(LoginDto $input, Request $request): Response
    {
        // NUNCA registrar $input en el log: lleva la contraseña en claro.
        $session = $this->login->execute($input, $request->getIp());

        return $this->withSession(
            response()->json($session->toArray() + ['message' => 'logged_in'], 200),
            $session,
        );
    }

    /**
     * «Continuar con Google»: entra o da de alta, según haga falta. El campo
     * `created` le dice al front si debe llevar al usuario al onboarding.
     */
    #[Route('/google', 'POST')]
    #[Throttle(10, 60)]
    public function loginWithGoogle(GoogleAuthDto $input): Response
    {
        $result  = $this->googleAuth->execute($input->id_token);
        $session = $result['session'];

        return $this->withSession(
            response()->json(
                $session->toArray() + [
                    'message' => $result['created'] ? 'registered' : 'logged_in',
                    'created' => $result['created'],
                ],
                $result['created'] ? 201 : 200,
            ),
            $session,
        );
    }

    #[Route('/logout', 'POST')]
    public function logout(Request $request): Response
    {
        // Revocación server-side, best-effort: sin esto el JWT seguiría siendo
        // válido hasta su `exp` aunque alguien lo hubiera capturado. La marca es
        // por usuario, así que esto cierra la sesión en TODOS los dispositivos.
        try {
            $userId = 0;

            if ($access = $request->cookie('access_token')) {
                $userId = $this->tokens->validateAccessToken($access)->sub;
            }
            if ($userId === 0 && ($refresh = $request->cookie('refresh_token'))) {
                $userId = $this->tokens->validateRefreshToken($refresh)->sub;
            }
            if ($userId > 0) {
                $this->revocation->invalidateUser($userId);
            }
        } catch (Throwable) {
            // Token ausente, vencido o ilegible: las cookies se borran igual.
        }

        return $this->cookies->clear(response()->json(['message' => 'logged_out'], 200));
    }

    /**
     * Renueva el access token con el refresh de la cookie.
     *
     * Existe como endpoint explícito para clientes que quieran refrescar a
     * propósito; en el flujo normal del navegador no hace falta llamarlo, porque
     * {@see AuthMiddleware} ya renueva sobre la marcha.
     */
    #[Route('/refresh', 'POST')]
    #[Throttle(30, 60)]
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->cookie('refresh_token');
        if (!$refreshToken) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'No hay sesión que renovar.'], 401);
        }

        try {
            $payload = $this->tokens->validateRefreshToken($refreshToken);
        } catch (Throwable) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'La sesión expiró.'], 401);
        }

        if (!$this->revocation->isTokenValid($payload->sub, $payload->iat)) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'La sesión fue revocada.'], 401);
        }

        // "Sigue activo": renovar la sesión cuenta como uso, no solo el login con
        // contraseña. Best-effort.
        try {
            $this->users->touchLastLogin($payload->sub);
        } catch (Throwable) {
            // sin efecto sobre el refresh
        }

        // Se respeta la elección original: sin "recordarme", cookie de sesión.
        $expires = TokenService::isRemembered($payload)
            ? time() + $this->tokens->refreshTtl(true)
            : 0;

        return $this->cookies->attachAccess(
            response()->json(['message' => 'refreshed'], 200),
            $this->tokens->createAccessToken($payload->sub, TokenService::customClaims($payload)),
            $this->tokens->createCsrfToken(),
            $expires,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PERFIL
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/me', 'GET')]
    #[Middleware(AuthMiddleware::class)]
    public function me(Request $request): Response
    {
        $userId = $this->userId($request);
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        return response()->json([
            'user' => [
                ...$user->toArray(),
                'roles'       => $this->users->getRoleNames($userId),
                'permissions' => $this->users->getEffectivePermissions($userId),
            ],
            // Para que el front pueda pintar (o no) el botón de Google sin
            // tener que conocer la configuración del servidor.
            'providers' => ['google' => $this->google->isConfigured()],
        ], 200);
    }

    #[Route('/me', 'PATCH')]
    #[Middleware(AuthMiddleware::class)]
    #[Middleware(CsrfMiddleware::class)]
    public function updateMe(UpdateProfileDto $input, Request $request): Response
    {
        $user = $this->users->updateProfile($this->userId($request), $input->name);

        if ($user === null) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        return response()->json(['message' => 'updated', 'user' => $user->toArray()], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFICACIÓN DE CORREO
    // ─────────────────────────────────────────────────────────────────────────

    /** Público: lo llama la pantalla que abre el enlace del correo. */
    #[Route('/verify-email', 'POST')]
    #[Throttle(10, 60)]
    public function verify(VerifyEmailDto $input): Response
    {
        if (!$this->verifyEmail->execute($input->token)) {
            throw new HttpException(
                'invalid_verification_token',
                'El enlace de verificación no es válido o ya se usó.',
                422
            );
        }

        return response()->json(['message' => 'email_verified'], 200);
    }

    #[Route('/resend-verification', 'POST')]
    #[Middleware(AuthMiddleware::class)]
    #[Middleware(CsrfMiddleware::class)]
    #[Throttle(3, 300)]
    public function resend(Request $request): Response
    {
        $result = $this->resendVerification->execute($this->userId($request));

        $body = ['message' => $result['already_verified'] ? 'already_verified' : 'sent'];

        if (!$this->config->isProduction && $result['verification_url'] !== null) {
            $body['verification_url'] = $result['verification_url'];
        }

        return response()->json($body, 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONTRASEÑA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Responde 200 SIEMPRE, exista o no el correo. Cualquier otra cosa —un 404,
     * un mensaje distinto— convertiría este endpoint en un detector de cuentas
     * registradas.
     */
    #[Route('/forgot-password', 'POST')]
    #[Throttle(3, 300)]
    public function forgotPassword(ForgotPasswordDto $input, Request $request): Response
    {
        $this->requestPasswordReset->execute($input->email, $input->recaptcha_token, $request->getIp());

        return response()->json([
            'message' => 'if_registered_sent',
            'detail'  => 'Si ese correo tiene una cuenta, te enviamos un enlace para restablecer la contraseña.',
        ], 200);
    }

    /** Público: lo llama la pantalla del enlace de restablecimiento. */
    #[Route('/reset-password', 'POST')]
    #[Throttle(10, 60)]
    public function reset(ResetPasswordDto $input): Response
    {
        if (!$this->resetPassword->execute($input->token, $input->password)) {
            throw new HttpException(
                'invalid_reset_token',
                'El enlace para restablecer la contraseña no es válido o ya expiró.',
                422
            );
        }

        return response()->json(['message' => 'password_reset'], 200);
    }

    #[Route('/change-password', 'POST')]
    #[Middleware(AuthMiddleware::class)]
    #[Middleware(CsrfMiddleware::class)]
    public function changeMyPassword(ChangePasswordDto $input, Request $request): Response
    {
        $userId = $this->userId($request);

        $validAfter = $this->changePassword->execute($userId, $input->current_password, $input->new_password);

        // El cambio revocó TODAS las sesiones, incluida esta. Se emiten
        // credenciales nuevas con `iat` = la marca de revocación, para que el
        // dispositivo desde el que se cambió la contraseña siga dentro y solo
        // los demás queden fuera.
        $expires = time() + $this->tokens->refreshTtl(true);

        return $this->cookies->attach(
            response()->json(['message' => 'password_changed'], 200),
            $this->tokens->createAccessToken($userId, issuedAt: $validAfter),
            $this->tokens->createRefreshToken($userId, true, issuedAt: $validAfter),
            $this->tokens->createCsrfToken(),
            $expires,
            $expires,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VINCULACIÓN CON GOOGLE
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/me/google', 'POST')]
    #[Middleware(AuthMiddleware::class)]
    #[Middleware(CsrfMiddleware::class)]
    #[Throttle(10, 60)]
    public function link(GoogleAuthDto $input, Request $request): Response
    {
        $result = $this->linkGoogle->execute($this->userId($request), $input->id_token);

        return response()->json(['message' => 'google_linked', 'google_email' => $result['email']], 200);
    }

    #[Route('/me/google', 'DELETE')]
    #[Middleware(AuthMiddleware::class)]
    #[Middleware(CsrfMiddleware::class)]
    public function unlink(Request $request): Response
    {
        $this->unlinkGoogle->execute($this->userId($request));

        return response()->json(['message' => 'google_unlinked'], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/health', 'GET')]
    #[Throttle(6, 60)]
    public function health(): Response
    {
        $db = $this->users->health();

        return response()->json(
            ['status' => $db['status'] === 'ok' ? 'ok' : 'degraded', 'database' => $db],
            $db['status'] === 'ok' ? 200 : 503,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Cuelga las cookies de sesión con la caducidad que corresponda al "recordarme". */
    private function withSession(Response $response, AuthSession $session): Response
    {
        // Sin "recordarme", el refresh es una cookie de SESIÓN (expires = 0):
        // muere al cerrar el navegador. El access comparte esa caducidad para que
        // no queden cookies huérfanas de una sesión que ya terminó.
        $expires = $session->remember
            ? time() + $this->tokens->refreshTtl(true)
            : 0;

        return $this->cookies->attach(
            $response,
            $session->accessToken,
            $session->refreshToken,
            $session->csrfToken,
            $expires,
            $expires,
        );
    }

    /**
     * ID del usuario autenticado. Se lee del atributo que publicó el
     * AuthMiddleware, nunca re-decodificando la cookie: tras un refresco
     * transparente, la cookie del request sigue siendo la vieja.
     */
    private function userId(Request $request): int
    {
        $user = $request->getAttribute('user');

        if (!$user instanceof JwtPayload || $user->sub <= 0) {
            throw new HttpException('unauthenticated', 'No hay sesión iniciada.', 401);
        }

        return $user->sub;
    }
}
