<?php

declare(strict_types=1);

namespace HexaLite\Mail;

use Psr\Log\LoggerInterface;

/**
 * Mailer de DESARROLLO: no envía nada, escribe el correo en el log.
 *
 * Es el que se usa cuando no hay ni SMTP ni Resend configurados, para que el
 * flujo completo de registro/verificación/reset funcione recién clonado el
 * proyecto — el enlace del correo aparece en el log y en la respuesta JSON
 * (esta última solo fuera de producción).
 *
 * `isConfigured()` devuelve false a propósito: sirve de señal para que el
 * arranque avise en producción de que los correos NO se están entregando.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $body = $text ?? trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        $this->logger?->info('[mail:log] Correo NO enviado (sin mailer configurado)', [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
        ]);

        if ($this->logger === null) {
            error_log("[mail:log] to=$to subject=$subject\n$body");
        }
    }
}
