<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Auth\Services\PasswordPolicy;
use HexaLite\Auth\Services\TokenRevocationService;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * Aplica la contraseña nueva a partir del token del correo. El token es de un
 * solo uso y con expiración; ambas cosas las comprueba el repositorio en la
 * MISMA consulta que actualiza, para que no haya ventana entre validar y aplicar.
 */
final readonly class ResetPassword
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordPolicy $passwords,
        private TokenRevocationService $revocation,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
    ) {
    }

    /** @return bool false si el token no existía o ya había vencido. */
    public function execute(string $token, string $newPassword): bool
    {
        $this->passwords->assert($newPassword);

        $result = $this->users->resetPasswordByToken(trim($token), $this->passwords->hash($newPassword));
        if ($result === null) {
            return false;
        }

        // Si el reset se pidió porque la cuenta estaba comprometida, cualquier
        // sesión abierta del atacante tiene que morir aquí.
        $this->revocation->invalidateUser($result['id']);

        $template = $this->templates->passwordChanged($result['name']);
        try {
            $this->mailer->send($result['email'], $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            error_log('[auth] No se pudo enviar el aviso de cambio de contraseña: ' . $e->getMessage());
        }

        return true;
    }
}
