<?php

declare(strict_types=1);

namespace HexaLite\Mail;

use RuntimeException;

/**
 * Envío a través de la API HTTP de Resend (https://resend.com) con cURL — sin el
 * SDK. Es la opción cómoda cuando no quieres administrar un servidor SMTP: solo
 * necesitas una API key y un dominio verificado.
 *
 * Variables: RESEND_API_KEY, MAIL_FROM_EMAIL, MAIL_FROM_NAME.
 */
final class ResendMailer implements MailerInterface
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromEmail,
        private readonly string $fromName = '',
        private readonly int $timeout = 15,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->fromEmail !== '';
    }

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Resend no configurado: faltan RESEND_API_KEY y/o MAIL_FROM_EMAIL.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('ResendMailer necesita la extensión ext-curl.');
        }

        $payload = [
            'from'    => $this->fromName !== ''
                ? sprintf('%s <%s>', $this->fromName, $this->fromEmail)
                : $this->fromEmail,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];
        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("No se pudo contactar con Resend: $error");
        }
        if ($status < 200 || $status >= 300) {
            // El cuerpo trae el motivo real (dominio sin verificar, key inválida…).
            $detail = is_string($response) ? trim($response) : '';
            throw new RuntimeException("Resend rechazó el envío (HTTP $status): $detail");
        }
    }
}
