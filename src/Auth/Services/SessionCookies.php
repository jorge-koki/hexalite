<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Auth\AuthConfig;
use HexaLite\Http\Response;

/**
 * Emite y borra el trío de cookies de sesión. Todos los endpoints de auth pasan
 * por aquí para que las opciones (domain, secure, samesite, path) sean siempre
 * las MISMAS: una cookie emitida con `domain` y borrada sin él no se borra, y el
 * usuario se queda con una sesión que el logout no mata.
 *
 *   access_token   HttpOnly  — credencial de cada petición.
 *   refresh_token  HttpOnly  — solo para renovar el acceso.
 *   csrf_token     legible   — el front lo lee y lo reenvía en X-CSRF-TOKEN
 *                              (double-submit). Por eso NO es HttpOnly: si lo
 *                              fuera, el JavaScript legítimo no podría copiarlo.
 */
final readonly class SessionCookies
{
    public function __construct(
        private AuthConfig $config,
    ) {
    }

    /**
     * Cuelga las tres cookies en la respuesta.
     *
     * @param int $accessExpires  Epoch de expiración de la cookie (0 = cookie de sesión).
     * @param int $refreshExpires Ídem para el refresh.
     */
    public function attach(
        Response $response,
        string $accessToken,
        string $refreshToken,
        string $csrfToken,
        int $accessExpires = 0,
        int $refreshExpires = 0,
    ): Response {
        return $response
            ->withCookie('access_token', $accessToken, $this->options($accessExpires, true))
            ->withCookie('refresh_token', $refreshToken, $this->options($refreshExpires, true))
            ->withCookie('csrf_token', $csrfToken, $this->options($accessExpires, false));
    }

    /** Renueva solo el access token (y su CSRF) tras un refresh. */
    public function attachAccess(Response $response, string $accessToken, string $csrfToken, int $expires = 0): Response
    {
        return $response
            ->withCookie('access_token', $accessToken, $this->options($expires, true))
            ->withCookie('csrf_token', $csrfToken, $this->options($expires, false));
    }

    /** Expira las tres cookies (logout). Mismas opciones que al emitirlas. */
    public function clear(Response $response): Response
    {
        $past = time() - 3600;

        return $response
            ->withCookie('access_token', '', $this->options($past, true))
            ->withCookie('refresh_token', '', $this->options($past, true))
            ->withCookie('csrf_token', '', $this->options($past, false));
    }

    /** @return array<string, mixed> */
    private function options(int $expires, bool $httpOnly): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => $this->config->cookieDomain,
            'secure'   => $this->config->secureCookies,
            'httponly' => $httpOnly,
            'samesite' => $this->config->sameSite,
        ];
    }
}
