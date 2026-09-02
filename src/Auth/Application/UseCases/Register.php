<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Application\AuthSession;
use HexaLite\Auth\Application\Dtos\RegisterDto;
use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Auth\Services\PasswordPolicy;
use HexaLite\Auth\Services\RecaptchaService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Http\HttpException;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * Alta pública: crea el usuario, manda el correo de verificación y deja la
 * sesión ya iniciada (auto-login), que es lo que espera cualquiera que acaba de
 * rellenar un formulario de registro.
 *
 * La verificación es BLANDA por defecto: el usuario entra y usa la app mientras
 * el front le muestra un aviso de "confirma tu correo". Se puede endurecer con
 * `AUTH_REQUIRE_VERIFIED_EMAIL=true`.
 */
final readonly class Register
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenService $tokens,
        private PasswordPolicy $passwords,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
        private AuthConfig $config,
        private ?RecaptchaService $recaptcha = null,
    ) {
    }

    /**
     * @return array{session: AuthSession, verification_url: string}
     *         `verification_url` solo se expone al cliente fuera de producción
     *         (lo decide el controlador); aquí se devuelve siempre para poder
     *         registrarlo y para los tests.
     */
    public function execute(RegisterDto $input, ?string $clientIp = null): array
    {
        $this->recaptcha?->verify($input->recaptcha_token, $clientIp);
        $this->passwords->assert($input->password);

        $email = strtolower(trim($input->email));

        // Pre-chequeo amable; la garantía DURA contra carreras es el índice único
        // sobre lower(email) que crea la migración.
        if ($this->users->emailExists($email)) {
            throw new HttpException('email_taken', 'Ese correo ya está registrado.', 409);
        }

        $verificationToken = bin2hex(random_bytes(32));

        $user = $this->users->create(
            name:              trim($input->name),
            email:             $email,
            passwordHash:      $this->passwords->hash($input->password),
            verificationToken: $verificationToken,
            emailVerified:     false,
            roles:             $this->config->defaultRoles,
        );

        $verificationUrl = $this->config->verificationUrl($verificationToken);
        $this->sendVerification($email, $user->name, $verificationUrl);

        $userId = (int) $user->id;

        return [
            'session' => new AuthSession(
                user:         $user,
                accessToken:  $this->tokens->createAccessToken($userId),
                refreshToken: $this->tokens->createRefreshToken($userId, true),
                csrfToken:    $this->tokens->createCsrfToken(),
                remember:     true,
                roles:        $this->users->getRoleNames($userId),
                permissions:  $this->users->getEffectivePermissions($userId),
            ),
            'verification_url' => $verificationUrl,
        ];
    }

    /**
     * El envío es best-effort: un fallo del proveedor de correo no puede tumbar
     * un alta que ya está en la base de datos. El usuario siempre puede pedir el
     * reenvío desde la app.
     */
    private function sendVerification(string $email, string $name, string $url): void
    {
        $template = $this->templates->verification($url, $name);

        try {
            $this->mailer->send($email, $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            // NUNCA registrar $url: lleva el token de verificación dentro.
            error_log("[auth] No se pudo enviar la verificación a $email: " . $e->getMessage());
        }
    }
}
