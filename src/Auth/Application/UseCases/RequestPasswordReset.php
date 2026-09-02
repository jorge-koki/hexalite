<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Auth\Services\RecaptchaService;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * «Olvidé mi contraseña». Anti-enumeración de principio a fin: se sale en
 * silencio en todos los caminos (correo vacío, cuenta inexistente, envío
 * fallido) y el controlador responde SIEMPRE lo mismo. Un atacante no puede
 * usar este endpoint para averiguar qué correos tienen cuenta.
 *
 * Ponle un #[Throttle] agresivo: cada llamada legítima manda un correo.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
        private AuthConfig $config,
        private ?RecaptchaService $recaptcha = null,
    ) {
    }

    public function execute(string $email, ?string $recaptchaToken = null, ?string $clientIp = null): void
    {
        $this->recaptcha?->verify($recaptchaToken, $clientIp);

        $email = trim($email);
        if ($email === '') {
            return;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || $user->id === null) {
            return;
        }

        $token  = bin2hex(random_bytes(32));
        $result = $this->users->createPasswordResetToken($user->id, $token, $this->config->resetTtlMinutes);
        if ($result === null) {
            return;
        }

        $template = $this->templates->passwordReset(
            $this->config->passwordResetUrl($token),
            $result['name'],
            $this->config->resetTtlMinutes,
        );

        try {
            $this->mailer->send($result['email'], $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            // NUNCA registrar la URL: lleva el token dentro.
            error_log('[auth] No se pudo enviar el restablecimiento: ' . $e->getMessage());
        }
    }
}
