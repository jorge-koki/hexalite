<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Http\HttpException;

/**
 * Verificación de reCAPTCHA (v2 y v3) contra Google.
 *
 * Sigue la regla del kit: si no hay `RECAPTCHA_SECRET`, la verificación se salta
 * en silencio y el login/registro funcionan igual. En cuanto pones el secreto,
 * el captcha pasa a ser OBLIGATORIO en los endpoints que lo usan — sin esto, un
 * bot se saltaría el reto simplemente no enviando el token.
 */
final readonly class RecaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * @param string $secret       RECAPTCHA_SECRET. Vacío = verificación desactivada.
     * @param float  $minimumScore Umbral de reCAPTCHA v3 (0.0–1.0). Se ignora en v2.
     */
    public function __construct(
        private string $secret = '',
        private float $minimumScore = 0.5,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secret !== '';
    }

    /**
     * @throws HttpException 422 si el reto no pasa, 503 si Google no responde.
     */
    public function verify(?string $token, ?string $remoteIp = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $token = trim((string) $token);
        if ($token === '') {
            throw new HttpException('recaptcha_missing', 'Falta la verificación anti-robots.', 422);
        }

        $payload = ['secret' => $this->secret, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $body = $this->post(self::VERIFY_URL, $payload);
        if ($body === null) {
            throw new HttpException('recaptcha_error', 'No se pudo verificar el reCAPTCHA.', 503);
        }

        $result = json_decode($body, true);
        if (!is_array($result) || empty($result['success'])) {
            throw new HttpException('recaptcha_failed', 'Verificación anti-robots fallida.', 422);
        }

        // reCAPTCHA v3 devuelve además una puntuación; v2 no manda `score`.
        if (isset($result['score']) && (float) $result['score'] < $this->minimumScore) {
            throw new HttpException('recaptcha_failed', 'Verificación anti-robots fallida.', 422);
        }
    }

    /** @param array<string, string> $fields */
    private function post(string $url, array $fields): ?string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $body  = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            return ($error === '' && is_string($body)) ? $body : null;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : null;
    }
}
