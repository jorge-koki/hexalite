<?php

declare(strict_types=1);

namespace HexaLite\Auth\Application\UseCases;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Auth\Services\PasswordPolicy;
use HexaLite\Auth\Services\TokenRevocationService;
use HexaLite\Http\HttpException;
use HexaLite\Mail\MailerInterface;
use Throwable;

/**
 * Cambio de la propia contraseña, verificando antes la actual.
 *
 * Revoca TODAS las sesiones al terminar. El dispositivo desde el que se hizo el
 * cambio no se queda fuera porque el controlador le emite credenciales nuevas
 * (con `iat` posterior a la marca de revocación); los demás sí, que es
 * exactamente lo que quieres si cambias la contraseña por sospecha.
 */
final readonly class ChangePassword
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordPolicy $passwords,
        private TokenRevocationService $revocation,
        private MailerInterface $mailer,
        private EmailTemplates $templates,
    ) {
    }

    /**
     * @return int Marca de revocación. El controlador acuña las credenciales
     *             nuevas de ESTE dispositivo con ese `iat` para que queden por
     *             encima de la marca y la sesión actual no se caiga.
     */
    public function execute(int $userId, string $currentPassword, string $newPassword): int
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new HttpException('user_not_found', 'Usuario no encontrado.', 404);
        }

        // Una cuenta solo-Google no tiene contraseña actual que verificar: debe
        // usar el flujo de "olvidé mi contraseña" para ponerse una por primera vez.
        if ($user->password === null) {
            throw new HttpException(
                'no_password_set',
                'Tu cuenta entra con Google y no tiene contraseña. Usa «olvidé mi contraseña» para crear una.',
                409
            );
        }

        if (!$user->verifyPassword($currentPassword)) {
            throw new HttpException('wrong_current_password', 'La contraseña actual no es correcta.', 422);
        }

        $this->passwords->assert($newPassword);
        $this->users->updatePassword($userId, $this->passwords->hash($newPassword));
        $validAfter = $this->revocation->invalidateUser($userId);

        $template = $this->templates->passwordChanged($user->name);
        try {
            $this->mailer->send($user->email, $template['subject'], $template['html'], $template['text']);
        } catch (Throwable $e) {
            error_log('[auth] No se pudo enviar el aviso de cambio de contraseña: ' . $e->getMessage());
        }

        return $validAfter;
    }
}
