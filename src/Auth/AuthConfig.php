<?php

declare(strict_types=1);

namespace HexaLite\Auth;

/**
 * Configuración del módulo de autenticación en un solo objeto inmutable.
 *
 * Existe porque los ajustes de sesión —sobre todo el `domain` y el `SameSite` de
 * las cookies— tienen que ser IDÉNTICOS en login, registro, refresh y logout. Si
 * cada endpoint los arma por su cuenta, tarde o temprano uno emite la cookie con
 * un dominio y otro intenta borrarla con otro, y el síntoma que ve el usuario es
 * "no puedo cerrar/iniciar sesión" sin ninguna pista en los logs.
 */
final readonly class AuthConfig
{
    /**
     * @param string $appName        Marca que aparece en los correos.
     * @param string $frontendUrl    Base del front para los enlaces de los correos.
     * @param string $cookieDomain   '' = host-only. En producción con subdominios: '.midominio.com'.
     * @param bool   $secureCookies  Cookies solo por HTTPS.
     * @param string $sameSite       'Lax' | 'None' | 'Strict'. Front y API en dominios distintos → 'None'.
     * @param bool   $isProduction   Fuera de producción se exponen los enlaces de verificación/reset en la respuesta.
     * @param int    $resetTtlMinutes Vigencia del enlace de restablecimiento.
     * @param bool   $requireVerifiedEmail Si true, el login exige el correo ya verificado (verificación DURA).
     * @param string[] $defaultRoles Roles asignados a quien se registra por su cuenta.
     * @param string $superRole    Rol con acceso total: salta la comprobación de permisos
     *                             puntuales del PermissionGuard. '' lo desactiva.
     */
    public function __construct(
        public string $appName = 'HexaLite',
        public string $frontendUrl = 'http://localhost:5173',
        public string $cookieDomain = '',
        public bool $secureCookies = false,
        public string $sameSite = 'Lax',
        public bool $isProduction = false,
        public int $resetTtlMinutes = 60,
        public bool $requireVerifiedEmail = false,
        public array $defaultRoles = ['user'],
        public string $superRole = '',
        public string $verifyEmailPath = '/auth/verify-email',
        public string $resetPasswordPath = '/auth/reset-password',
    ) {
    }

    /**
     * Construye la configuración desde el entorno:
     *   APP_NAME, APP_ENV, FRONTEND_URL (o FRONTEND_ORIGINS), COOKIE_DOMAIN,
     *   COOKIE_SECURE, COOKIE_SAMESITE, AUTH_RESET_TTL_MINUTES,
     *   AUTH_REQUIRE_VERIFIED_EMAIL, AUTH_DEFAULT_ROLES, AUTH_SUPER_ROLE.
     *
     * En producción, los valores por defecto de las cookies se endurecen solos
     * (Secure + SameSite=None), que es lo que necesita un front en otro dominio.
     */
    public static function fromEnv(): self
    {
        $isProduction = self::env('APP_ENV') === 'production';

        $roles = array_values(array_filter(array_map(
            'trim',
            explode(',', self::env('AUTH_DEFAULT_ROLES') ?? 'user')
        )));

        return new self(
            appName:              self::env('APP_NAME') ?? 'HexaLite',
            frontendUrl:          self::frontendUrl(),
            cookieDomain:         self::env('COOKIE_DOMAIN') ?? '',
            secureCookies:        self::flag('COOKIE_SECURE', $isProduction),
            sameSite:             self::env('COOKIE_SAMESITE') ?? ($isProduction ? 'None' : 'Lax'),
            isProduction:         $isProduction,
            resetTtlMinutes:      (int) (self::env('AUTH_RESET_TTL_MINUTES') ?? 60),
            requireVerifiedEmail: self::flag('AUTH_REQUIRE_VERIFIED_EMAIL', false),
            defaultRoles:         $roles,
            superRole:            self::env('AUTH_SUPER_ROLE') ?? '',
        );
    }

    /** Enlace del correo de verificación. */
    public function verificationUrl(string $token): string
    {
        return rtrim($this->frontendUrl, '/') . $this->verifyEmailPath . '?token=' . urlencode($token);
    }

    /** Enlace del correo de restablecimiento. */
    public function passwordResetUrl(string $token): string
    {
        return rtrim($this->frontendUrl, '/') . $this->resetPasswordPath . '?token=' . urlencode($token);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Base del frontend: FRONTEND_URL, o el primer origen de FRONTEND_ORIGINS
     * (la lista que ya usa CORS), o localhost en desarrollo.
     */
    private static function frontendUrl(): string
    {
        $url = self::env('FRONTEND_URL');
        if ($url !== null) {
            return rtrim($url, '/');
        }

        $first = trim(explode(',', self::env('FRONTEND_ORIGINS') ?? '')[0]);

        return $first !== '' ? rtrim($first, '/') : 'http://localhost:5173';
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }

    private static function flag(string $key, bool $default): bool
    {
        $value = self::env($key);
        if ($value === null) {
            return $default;
        }
        return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }
}
