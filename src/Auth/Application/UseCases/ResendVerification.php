<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * Reenvía el correo de verificación al usuario con la sesión iniciada, con un
 * token NUEVO (el anterior deja de valer). Conviene limitarlo con #[Throttle]:
 * es un endpoint que manda correos.
 */
final readonly class ResendVerification
{
    public function __construct(
        private UserRepositoryInterface $users,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
        private AuthConfig $config,
    ) {
    }

    /**
     * @return array{already_verified: bool, verification_url: ?string}
     */
    public function execute(int $userId): array
    {
        $token  = bin2hex(random_bytes(32));
        $result = $this->users->refreshVerificationToken($userId, $token);

        // Usuario inexistente o ya verificado: se responde lo mismo. No hay nada
        // que enviar y tampoco motivo para dar detalles.
        if ($result === null || $result['already_verified']) {
            return ['already_verified' => true, 'verification_url' => null];
        }

        $url      = $this->config->verificationUrl($token);
        $template = $this->templates->verification($url, $result['name']);

        try {
            $this->mailer->send($result['email'], $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            error_log('[auth] No se pudo reenviar la verificación: ' . $e->getMessage());
        }

        return ['already_verified' => false, 'verification_url' => $url];
    }
}
