<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

/**
 * Plantillas de los correos de cuenta: verificación, restablecer contraseña y
 * aviso de cambio. Cada método devuelve asunto + HTML + texto plano.
 *
 * El HTML usa tablas y estilos en línea a propósito: es lo único que renderizan
 * bien Outlook y Gmail. Todo lo interpolado se escapa — un nombre de usuario con
 * `<script>` no debe acabar ejecutándose en el cliente de correo de nadie.
 *
 * @phpstan-type Email array{subject: string, html: string, text: string}
 */
final readonly class EmailTemplates
{
    public function __construct(
        private string $brand = 'HexaLite',
        /** Color del botón de acción. */
        private string $accentColor = '#1e293b',
    ) {
    }

    /** @return array{subject: string, html: string, text: string} */
    public function verification(string $url, ?string $name = null): array
    {
        $hi    = self::greeting($name);
        $intro = 'Gracias por crear tu cuenta. Para empezar, confirma que este correo es tuyo.';

        return [
            'subject' => 'Verifica tu correo — ' . $this->brand,
            'html'    => $this->layout(
                'Confirma tu correo',
                $hi,
                $intro,
                'Verificar mi correo',
                $url,
                'Si tú no creaste esta cuenta, puedes ignorar este mensaje.',
            ),
            'text'    => "$hi\n\n$intro\n\nVerifica tu correo aquí: $url\n\n"
                . 'Si tú no creaste esta cuenta, ignora este mensaje.',
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    public function passwordReset(string $url, ?string $name = null, int $ttlMinutes = 60): array
    {
        $hi    = self::greeting($name);
        $vence = $ttlMinutes >= 60
            ? 'El enlace vence en ' . intdiv($ttlMinutes, 60) . ' hora(s).'
            : "El enlace vence en $ttlMinutes minutos.";
        $intro = "Recibimos una solicitud para restablecer tu contraseña. Crea una nueva con el botón de abajo. $vence";

        return [
            'subject' => 'Restablece tu contraseña — ' . $this->brand,
            'html'    => $this->layout(
                'Restablecer contraseña',
                $hi,
                $intro,
                'Crear nueva contraseña',
                $url,
                'Si tú no lo solicitaste, ignora este correo: tu contraseña no cambiará.',
            ),
            'text'    => "$hi\n\n$intro\n\nRestablece tu contraseña aquí: $url\n\n"
                . 'Si tú no lo solicitaste, ignora este correo.',
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    public function passwordChanged(?string $name = null): array
    {
        $hi    = self::greeting($name);
        $intro = 'Te confirmamos que la contraseña de tu cuenta se cambió correctamente. '
            . 'Si fuiste tú, no tienes que hacer nada.';
        $warn  = 'Si NO fuiste tú, restablece tu contraseña de inmediato y contacta a soporte.';

        return [
            'subject' => 'Tu contraseña fue cambiada — ' . $this->brand,
            // Sin botón: un aviso de seguridad con un enlace es indistinguible de
            // un phishing, y aquí no hay ninguna acción que el usuario deba tomar.
            'html'    => $this->layout('Contraseña actualizada', $hi, $intro, null, null, $warn),
            'text'    => "$hi\n\n$intro\n\n$warn",
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    public function welcome(string $loginUrl, ?string $name = null): array
    {
        $hi    = self::greeting($name);
        $intro = 'Tu correo quedó confirmado y tu cuenta ya está activa. Entra cuando quieras.';

        return [
            'subject' => 'Tu cuenta ya está activa — ' . $this->brand,
            'html'    => $this->layout(
                'Tu cuenta está lista',
                $hi,
                $intro,
                'Entrar a ' . $this->brand,
                $loginUrl,
                'Si no reconoces esta cuenta, puedes ignorar este mensaje.',
            ),
            'text'    => "$hi\n\n$intro\n\nEntra aquí: $loginUrl",
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function greeting(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? "Hola, $name:" : 'Hola:';
    }

    private function layout(
        string $title,
        string $greeting,
        string $intro,
        ?string $buttonLabel,
        ?string $buttonUrl,
        string $footnote,
    ): string {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $brand    = $esc($this->brand);
        $accent   = $esc($this->accentColor);
        $title    = $esc($title);
        $greeting = $esc($greeting);
        $intro    = $esc($intro);
        $footnote = $esc($footnote);

        $button = '';
        if ($buttonLabel !== null && $buttonUrl !== null) {
            $safeUrl   = $esc($buttonUrl);
            $safeLabel = $esc($buttonLabel);
            $button = <<<HTML
                <tr>
                  <td style="padding:8px 0 24px;">
                    <a href="$safeUrl"
                       style="display:inline-block;background:$accent;color:#ffffff;text-decoration:none;
                              font-weight:600;font-size:15px;padding:12px 24px;border-radius:8px;">$safeLabel</a>
                  </td>
                </tr>
                <tr>
                  <td style="font-size:12px;color:#94a3b8;padding-bottom:16px;word-break:break-all;">
                    Si el botón no funciona, copia esta dirección en tu navegador:<br>$safeUrl
                  </td>
                </tr>
                HTML;
        }

        return <<<HTML
            <!doctype html>
            <html lang="es">
            <body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
                <tr>
                  <td align="center">
                    <table role="presentation" width="480" cellpadding="0" cellspacing="0"
                           style="background:#ffffff;border-radius:12px;padding:32px;max-width:480px;">
                      <tr><td style="font-size:18px;font-weight:700;color:$accent;padding-bottom:16px;">$brand</td></tr>
                      <tr><td style="font-size:20px;font-weight:700;padding-bottom:8px;">$title</td></tr>
                      <tr><td style="font-size:15px;padding-bottom:4px;">$greeting</td></tr>
                      <tr><td style="font-size:15px;line-height:1.5;color:#334155;padding-bottom:16px;">$intro</td></tr>
                      $button
                      <tr><td style="font-size:13px;line-height:1.5;color:#64748b;border-top:1px solid #e2e8f0;padding-top:16px;">$footnote</td></tr>
                    </table>
                  </td>
                </tr>
              </table>
            </body>
            </html>
            HTML;
    }
}
