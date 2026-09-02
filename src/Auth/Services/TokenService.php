<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Auth\Exceptions\ExpiredTokenException;
use HexaLite\Auth\Exceptions\InvalidTokenException;
use RuntimeException;

/**
 * Emisión y verificación de JWT (HS256) SIN dependencias: `hash_hmac` y
 * `hash_equals` bastan para HMAC-SHA256, y así el kit de auth no arrastra un
 * paquete de terceros.
 *
 * Modelo de dos tokens:
 *   • ACCESS  — corto (15 min por defecto), viaja en cada petición.
 *   • REFRESH — largo (7 días, o 30 con "recordarme"), firmado con OTRA clave y
 *     con `jti` propio. Solo sirve para acuñar accesos nuevos.
 *
 * Se firma con claves distintas a propósito: si se filtra la de acceso, el
 * atacante no puede fabricarse un refresh y quedarse dentro para siempre.
 */
final class TokenService
{
    private const ALGORITHM = 'HS256';

    /** Claims que gestiona el propio servicio; el resto son de la aplicación. */
    public const RESERVED_CLAIMS = ['iss', 'iat', 'exp', 'sub', 'jti', 'type', 'rmb'];

    private string $secret;
    private string $refreshSecret;

    /**
     * @param string|null $secret         Clave de los access token (JWT_SECRET).
     * @param string|null $refreshSecret  Clave de los refresh token (JWT_REFRESH_SECRET).
     * @param int         $accessTtl      Vida del access token en segundos.
     * @param int         $refreshTtl     Vida del refresh token en segundos.
     * @param int         $rememberTtl    Vida del refresh con "recordarme".
     * @param string      $issuer         Claim `iss`.
     * @param int         $leeway         Tolerancia de reloj en segundos entre servidores.
     */
    public function __construct(
        ?string $secret = null,
        ?string $refreshSecret = null,
        private readonly int $accessTtl = 900,
        private readonly int $refreshTtl = 604800,
        private readonly int $rememberTtl = 2592000,
        private readonly string $issuer = 'hexalite',
        private readonly int $leeway = 30,
    ) {
        $this->secret = $secret ?? self::env('JWT_SECRET');
        // REFRESH_TOKEN se acepta como alias heredado de JWT_REFRESH_SECRET.
        $this->refreshSecret = $refreshSecret
            ?? (self::env('JWT_REFRESH_SECRET') ?: self::env('REFRESH_TOKEN'));

        // FALLAR CERRADO: nunca firmar con clave vacía. Un HS256 con clave '' es
        // trivial de falsificar — cualquiera podría emitirse un token de admin.
        if ($this->secret === '' || $this->refreshSecret === '') {
            throw new RuntimeException(
                'JWT_SECRET y JWT_REFRESH_SECRET son obligatorias: se aborta para no firmar tokens con clave vacía. '
                . 'Genera cada una con: php -r "echo bin2hex(random_bytes(32));"'
            );
        }
        if ($this->secret === $this->refreshSecret) {
            throw new RuntimeException(
                'JWT_SECRET y JWT_REFRESH_SECRET deben ser DISTINTAS: con la misma clave, '
                . 'un access token filtrado sirve también como refresh.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMISIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Access token de corta duración.
     *
     * @param array<string, mixed> $claims Claims extra de tu app (tenant, plan…).
     *                                     NUNCA metas datos sensibles: el payload
     *                                     de un JWT va firmado, no cifrado.
     * @param int|null $issuedAt `iat` explícito. Se usa al re-acuñar credenciales
     *                           justo después de revocar las sesiones, para que el
     *                           token nuevo quede POR ENCIMA de la marca de
     *                           revocación y el dispositivo actual no se caiga.
     */
    public function createAccessToken(int $userId, array $claims = [], ?int $issuedAt = null): string
    {
        $now = $issuedAt ?? time();

        return $this->encode([
            ...$claims,
            'iss'  => $this->issuer,
            'iat'  => $now,
            'exp'  => $now + $this->accessTtl,
            'sub'  => $userId,
            'type' => 'access',
        ], $this->secret);
    }

    /**
     * Refresh token de larga duración.
     *
     * @param bool     $remember "Recordarme": alarga la vida del token.
     * @param int|null $issuedAt `iat` explícito (ver {@see createAccessToken()}).
     */
    public function createRefreshToken(
        int $userId,
        bool $remember = false,
        array $claims = [],
        ?int $issuedAt = null,
    ): string {
        $now = $issuedAt ?? time();

        return $this->encode([
            ...$claims,
            'iss'  => $this->issuer,
            'iat'  => $now,
            'exp'  => $now + ($remember ? $this->rememberTtl : $this->refreshTtl),
            'sub'  => $userId,
            'jti'  => bin2hex(random_bytes(16)),
            'type' => 'refresh',
            // Se recuerda la elección del usuario DENTRO del token. Sin esto, al
            // renovar la sesión no habría forma de saber si sus cookies debían
            // seguir siendo de sesión (mueren al cerrar el navegador) o
            // persistentes, y un "no me recuerdes" acabaría convertido en 30 días.
            'rmb'  => $remember,
        ], $this->refreshSecret);
    }

    /**
     * Claims propios de la app dentro de un token, sin los reservados. Sirve para
     * arrastrarlos al token nuevo al renovar la sesión: si se perdieran, el
     * usuario acabaría con un access token sin su tenant, su plan, etc.
     *
     * @return array<string, mixed>
     */
    public static function customClaims(JwtPayload $payload): array
    {
        return array_diff_key($payload->claims, array_flip(self::RESERVED_CLAIMS));
    }

    /** ¿El refresh se emitió con «recordarme»? Decide si sus cookies persisten. */
    public static function isRemembered(JwtPayload $payload): bool
    {
        return (bool) $payload->get('rmb', false);
    }

    /** Token CSRF para el patrón double-submit cookie. */
    public function createCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFICACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @throws ExpiredTokenException|InvalidTokenException
     */
    public function validateAccessToken(string $token): JwtPayload
    {
        $payload = $this->decode($token, $this->secret);

        if (!$payload->isAccessToken()) {
            // Un refresh no debe valer como acceso: son secretos distintos, pero
            // el chequeo explícito documenta la intención y protege ante cambios.
            throw new InvalidTokenException('Se esperaba un token de acceso.');
        }

        return $payload;
    }

    /**
     * @throws ExpiredTokenException|InvalidTokenException
     */
    public function validateRefreshToken(string $token): JwtPayload
    {
        $payload = $this->decode($token, $this->refreshSecret);

        if (!$payload->isRefreshToken()) {
            throw new InvalidTokenException('Se esperaba un token de refresco.');
        }

        return $payload;
    }

    public function accessTtl(): int
    {
        return $this->accessTtl;
    }

    public function refreshTtl(bool $remember = false): int
    {
        return $remember ? $this->rememberTtl : $this->refreshTtl;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JWT (HS256)
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $claims */
    private function encode(array $claims, string $key): string
    {
        $header  = self::b64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM]));
        $payload = self::b64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = self::b64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $key, true)
        );

        return "$header.$payload.$signature";
    }

    /**
     * @throws ExpiredTokenException|InvalidTokenException
     */
    private function decode(string $token, string $key): JwtPayload
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('El token no tiene el formato de un JWT.');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode((string) self::b64UrlDecode($header64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            // Rechazar cualquier otro `alg` cierra el ataque clásico de "alg: none"
            // y el de degradar la firma a un algoritmo que no verificamos.
            throw new InvalidTokenException('Algoritmo de firma no soportado.');
        }

        // hash_equals: comparación en tiempo constante. Con `===` un atacante
        // puede deducir la firma byte a byte midiendo tiempos.
        $expected = hash_hmac('sha256', "$header64.$payload64", $key, true);
        if (!hash_equals($expected, (string) self::b64UrlDecode($signature64))) {
            throw new InvalidTokenException('La firma del token no es válida.');
        }

        $claims = json_decode((string) self::b64UrlDecode($payload64), true);
        if (!is_array($claims)) {
            throw new InvalidTokenException('El contenido del token no es un JSON válido.');
        }

        $now = time();

        if (isset($claims['nbf']) && $now + $this->leeway < (int) $claims['nbf']) {
            throw new ExpiredTokenException('El token todavía no es válido.');
        }
        if (isset($claims['exp']) && $now - $this->leeway >= (int) $claims['exp']) {
            throw new ExpiredTokenException('El token ha expirado.');
        }

        return JwtPayload::fromClaims($claims);
    }

    private static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private static function env(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_string($value) ? trim($value) : '';
    }
}
