<?php

declare(strict_types=1);

namespace HexaLite\Mail;

use Psr\Log\LoggerInterface;

/**
 * Elige el mailer según el entorno, con el mismo criterio que el resto del kit:
 * se activa lo que esté configurado y, si no hay nada, se degrada a algo que no
 * rompe.
 *
 *   MAIL_MAILER=smtp|resend|log   fuerza uno concreto (opcional)
 *   RESEND_API_KEY                → ResendMailer
 *   MAIL_HOST                     → SmtpMailer
 *   nada de lo anterior           → LogMailer (el correo aparece en el log)
 *
 * Comunes: MAIL_FROM_EMAIL, MAIL_FROM_NAME.
 * SMTP:    MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION.
 */
final class MailerFactory
{
    public static function fromEnv(?LoggerInterface $logger = null): MailerInterface
    {
        $driver    = strtolower((string) (self::env('MAIL_MAILER') ?? ''));
        $fromEmail = self::env('MAIL_FROM_EMAIL') ?? self::env('RESEND_FROM_EMAIL') ?? '';
        $fromName  = self::env('MAIL_FROM_NAME') ?? self::env('RESEND_FROM_NAME') ?? '';
        $apiKey    = self::env('RESEND_API_KEY') ?? '';
        $host      = self::env('MAIL_HOST') ?? '';

        // Sin driver explícito se deduce de lo que esté configurado.
        if ($driver === '') {
            $driver = match (true) {
                $apiKey !== '' => 'resend',
                $host   !== '' => 'smtp',
                default        => 'log',
            };
        }

        return match ($driver) {
            'resend' => new ResendMailer($apiKey, $fromEmail, $fromName),
            'smtp'   => new SmtpMailer(
                host:       $host,
                port:       (int) (self::env('MAIL_PORT') ?? 587),
                username:   self::env('MAIL_USERNAME') ?? '',
                password:   self::env('MAIL_PASSWORD') ?? '',
                encryption: strtolower(self::env('MAIL_ENCRYPTION') ?? 'tls'),
                fromEmail:  $fromEmail !== '' ? $fromEmail : (self::env('MAIL_USERNAME') ?? ''),
                fromName:   $fromName,
            ),
            default  => new LogMailer($logger),
        };
    }

    /** Variable de entorno no vacía, o null. */
    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }
}
