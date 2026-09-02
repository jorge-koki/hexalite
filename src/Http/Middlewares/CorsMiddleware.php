<?php

declare(strict_types=1);

namespace HexaLite\Http\Middlewares;

use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * CORS con credenciales seguras por defecto.
 *
 * La regla que más se rompe en la práctica: con `Access-Control-Allow-Credentials:
 * true` NUNCA se puede reflejar un Origin arbitrario ni usar `*`. Si lo haces,
 * cualquier web puede leer respuestas autenticadas con la sesión de la víctima.
 * Aquí, con credenciales activadas, solo se responde el Origin si está
 * EXPLÍCITAMENTE en la allowlist.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $allowedOrigins Orígenes permitidos ('*' solo sin credenciales).
     * @param string[] $allowedMethods
     * @param string[] $allowedHeaders
     */
    public function __construct(
        private readonly array $allowedOrigins = ['*'],
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
        private readonly bool $allowCredentials = false,
        private readonly int $maxAge = 86400,
    ) {
    }

    /**
     * Configuración desde el entorno:
     *   FRONTEND_ORIGINS  lista separada por comas (p. ej. "https://app.midominio.com").
     *   CORS_CREDENTIALS  '0' para desactivar el envío de cookies (por defecto activado
     *                     cuando hay orígenes concretos, que es lo que necesita el kit de auth).
     */
    public static function fromEnv(): self
    {
        $raw = getenv('FRONTEND_ORIGINS') ?: '';
        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($origins === []) {
            // Sin allowlist no se pueden usar credenciales: se cae a modo abierto
            // SIN cookies, que es lo único seguro que se puede hacer aquí.
            return new self(['*'], allowCredentials: false);
        }

        $credentials = strtolower((string) (getenv('CORS_CREDENTIALS') ?: '1'));

        return new self(
            $origins,
            allowCredentials: !in_array($credentials, ['0', 'false', 'no', 'off'], true),
        );
    }

    public function handle(Request $request, callable $next): Response
    {
        $headers = $this->headersFor($request->getHeader('Origin'));

        // Preflight: se responde de inmediato, sin tocar la app.
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, $headers);
        }

        return $next($request)->withHeaders($headers);
    }

    /** @return array<string, string> */
    private function headersFor(?string $origin): array
    {
        $allowed = $this->resolveOrigin($origin);

        $headers = [
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age'       => (string) $this->maxAge,
        ];

        if ($allowed !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowed;
        }

        // Solo tiene sentido —y solo es válido— con un origen concreto.
        if ($this->allowCredentials && $allowed !== null && $allowed !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            // El Origin cambia la respuesta: sin Vary, un proxy podría servir a un
            // sitio la respuesta cacheada para otro.
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    private function resolveOrigin(?string $origin): ?string
    {
        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            return $origin;
        }

        if (!$this->allowCredentials && in_array('*', $this->allowedOrigins, true)) {
            return '*';
        }

        // Origen no permitido: se devuelve el primer origen concreto de la lista.
        // El navegador verá que no coincide con el suyo y bloqueará la petición.
        $explicit = array_values(array_filter($this->allowedOrigins, static fn($o) => $o !== '*'));

        return $explicit[0] ?? null;
    }
}
