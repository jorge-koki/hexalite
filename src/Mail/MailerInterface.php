<?php

declare(strict_types=1);

namespace HexaLite\Mail;

/**
 * Envío de correo transaccional. El framework la usa para los correos de cuenta
 * (verificación, restablecer contraseña, aviso de cambio); tu app puede usarla
 * para lo que quiera.
 *
 * Las implementaciones LANZAN en caso de fallo: el que llama decide si eso debe
 * tumbar la operación o solo registrarse (en los flujos de auth, un correo que
 * no sale nunca debe tumbar el registro ni delatar si una cuenta existe).
 */
interface MailerInterface
{
    /**
     * @param string      $to      Destinatario.
     * @param string      $subject Asunto.
     * @param string      $html    Cuerpo HTML.
     * @param string|null $text    Alternativa en texto plano (recomendada: mejora la entregabilidad).
     *
     * @throws \RuntimeException si el envío falla.
     */
    public function send(string $to, string $subject, string $html, ?string $text = null): void;

    /** ¿Este mailer puede entregar de verdad? (Un mailer sin credenciales, no.) */
    public function isConfigured(): bool;
}
