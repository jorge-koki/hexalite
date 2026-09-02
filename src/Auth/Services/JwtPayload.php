<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

/**
 * Claims ya validados de un JWT. Es lo que un middleware de auth publica en
 * `$request->setAttribute('user', ...)` y lo que devuelve `$request->user()`.
 *
 * Los claims estándar están tipados; cualquier claim extra que hayas metido en
 * el token (tenant, plan, lo que sea) sigue accesible con {@see get()}.
 */
final readonly class JwtPayload
{
    /**
     * @param int                  $sub    ID del usuario.
     * @param string               $type   'access' | 'refresh'.
     * @param array<string, mixed> $claims Payload completo, incluidos los claims propios.
     */
    public function __construct(
        public int $sub,
        public int $iat = 0,
        public int $exp = 0,
        public string $iss = '',
        public string $jti = '',
        public string $type = 'access',
        public array $claims = [],
    ) {
    }

    /** @param array<string, mixed> $claims */
    public static function fromClaims(array $claims): self
    {
        return new self(
            sub:    (int) ($claims['sub'] ?? 0),
            iat:    (int) ($claims['iat'] ?? 0),
            exp:    (int) ($claims['exp'] ?? 0),
            iss:    (string) ($claims['iss'] ?? ''),
            jti:    (string) ($claims['jti'] ?? ''),
            type:   (string) ($claims['type'] ?? 'access'),
            claims: $claims,
        );
    }

    /** Claim arbitrario del token (los que tu app haya añadido). */
    public function get(string $claim, mixed $default = null): mixed
    {
        return $this->claims[$claim] ?? $default;
    }

    public function isAccessToken(): bool
    {
        return $this->type === 'access';
    }

    public function isRefreshToken(): bool
    {
        return $this->type === 'refresh';
    }
}
