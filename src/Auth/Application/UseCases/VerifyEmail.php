<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * Consume el token del enlace «verifica tu correo». El token es de un solo uso:
 * al aplicarlo se borra, así que reabrir el enlace ya no vale (y por eso el
 * controlador responde 422 en el segundo intento, no un 500).
 */
final readonly class VerifyEmail
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
        private AuthConfig $config,
    ) {
    }

    /** @return bool true si el token era válido y el correo quedó verificado. */
    public function execute(string $token): bool
    {
        $result = $this->users->verifyEmailByToken(trim($token));
        if ($result === null) {
            return false;
        }

        // Bienvenida. Best-effort: la verificación ya está hecha en la BD.
        $template = $this->templates->welcome($this->config->frontendUrl, $result['name']);
        try {
            $this->mailer->send($result['email'], $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            error_log('[auth] No se pudo enviar la bienvenida: ' . $e->getMessage());
        }

        return true;
    }
}
