<?php

declare(strict_types=1);

namespace HexaLite\Mail;

use RuntimeException;

/**
 * Cliente SMTP mínimo escrito sobre sockets nativos — SIN dependencias.
 *
 * Cubre lo que necesita un correo transaccional: STARTTLS o SMTPS, AUTH
 * LOGIN/PLAIN, y un cuerpo `multipart/alternative` (texto + HTML) con las
 * cabeceras codificadas para acentos. Si tu app ya usa PHPMailer o Symfony
 * Mailer, implementa {@see MailerInterface} con ellos y enlázalo en el
 * contenedor; esta clase existe para que el kit funcione recién instalado.
 */
final class SmtpMailer implements MailerInterface
{
    /** Fin de línea del protocolo SMTP (RFC 5321: siempre CRLF, no "\n"). */
    private const CRLF = "\r\n";

    /**
     * @param string $encryption 'tls' (STARTTLS, puerto 587) | 'ssl' (SMTPS, 465) | 'none'
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $encryption = 'tls',
        private readonly string $fromEmail = '',
        private readonly string $fromName = '',
        private readonly int $timeout = 20,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->fromEmail !== '';
    }

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SMTP no configurado: faltan MAIL_HOST y/o MAIL_FROM_EMAIL.');
        }

        $socket = $this->open();

        try {
            $this->expect($socket, 220);
            $this->handshake($socket);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (@stream_socket_enable_crypto($socket, true, $crypto) !== true) {
                    throw new RuntimeException("No se pudo negociar TLS con {$this->host}:{$this->port}.");
                }
                // Tras STARTTLS hay que repetir el EHLO: la lista de extensiones
                // del servidor cambia (AUTH suele anunciarse solo cifrado).
                $this->handshake($socket);
            }

            if ($this->username !== '') {
                $this->authenticate($socket);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromEmail . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            fwrite($socket, $this->buildMessage($to, $subject, $html, $text) . self::CRLF . '.' . self::CRLF);
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', [221, 250]);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROTOCOLO
    // ─────────────────────────────────────────────────────────────────────────

    /** @return resource */
    private function open()
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errNo,
            $errStr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "No se pudo conectar a {$this->host}:{$this->port} — " . ($errStr ?: "error $errNo")
            );
        }

        stream_set_timeout($socket, $this->timeout);
        return $socket;
    }

    /** @param resource $socket */
    private function handshake($socket): void
    {
        // El nombre que anunciamos debe ser un FQDN o un literal entre corchetes.
        $hostname = gethostname() ?: 'localhost';
        try {
            $this->command($socket, "EHLO $hostname", 250);
        } catch (RuntimeException) {
            // Servidor antiguo que no habla ESMTP.
            $this->command($socket, "HELO $hostname", 250);
        }
    }

    /** @param resource $socket */
    private function authenticate($socket): void
    {
        try {
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->username), 334);
            $this->command($socket, base64_encode($this->password), 235);
        } catch (RuntimeException) {
            // Algunos servidores solo aceptan PLAIN; se reintenta una vez antes
            // de dar la autenticación por fallida.
            $plain = base64_encode("\0{$this->username}\0{$this->password}");
            $this->command($socket, "AUTH PLAIN $plain", 235);
        }
    }

    /**
     * @param resource         $socket
     * @param int|array<int>   $expected Código(s) de respuesta aceptables.
     */
    private function command($socket, string $command, int|array $expected): string
    {
        fwrite($socket, $command . self::CRLF);
        return $this->expect($socket, $expected);
    }

    /**
     * Lee la respuesta completa (soporta respuestas multilínea "250-...") y
     * verifica el código.
     *
     * @param resource       $socket
     * @param int|array<int> $expected
     */
    private function expect($socket, int|array $expected): string
    {
        $expected = (array) $expected;
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // En una respuesta multilínea el 4º carácter es '-'; en la última, ' '.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('El servidor SMTP cerró la conexión sin responder.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP respondió: ' . trim($response));
        }

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENSAJE
    // ─────────────────────────────────────────────────────────────────────────

    private function buildMessage(string $to, string $subject, string $html, ?string $text): string
    {
        $boundary = 'hx-' . bin2hex(random_bytes(12));
        $from     = $this->fromName !== ''
            ? self::encodeHeader($this->fromName) . " <{$this->fromEmail}>"
            : $this->fromEmail;

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $text ??= trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        $body = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($text), 76, self::CRLF),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html), 76, self::CRLF),
            '--' . $boundary . '--',
        ];

        $message = implode(self::CRLF, $headers) . self::CRLF . self::CRLF . implode(self::CRLF, $body);

        // Dot-stuffing (RFC 5321 §4.5.2): una línea que empieza con '.' cerraría
        // el DATA antes de tiempo, así que se duplica el punto.
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    /** Codifica una cabecera con acentos (RFC 2047) y corta inyección de CRLF. */
    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return preg_match('/[\x80-\xFF]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
