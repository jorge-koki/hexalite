<?php

declare(strict_types=1);

namespace HexaLite\Auth\Middlewares;

use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Cabeceras de seguridad para respuestas de API (JSON y descargas).
 *
 * La CSP es deliberadamente cerrada: una API no carga recursos ni se embebe como
 * página, así que `default-src 'none'` no rompe nada y cierra de golpe los
 * vectores de clickjacking, secuestro de URLs relativas y plugins. La CSP del
 * DOCUMENTO (script-src, style-src, connect-src de tu SPA) es otra cosa y va en
 * el servidor que sirve el front, no aquí.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param bool $hsts Enviar Strict-Transport-Security. Actívalo SOLO si el
     *                   dominio va entero por HTTPS: sobre http:// no hace nada,
     *                   y un subdominio interno sin certificado se vuelve
     *                   inaccesible durante todo el max-age.
     */
    public function __construct(
        private readonly bool $hsts = true,
        private readonly int $hstsMaxAge = 31536000,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; "
                . "form-action 'self'; object-src 'none'",
        ];

        if ($this->hsts) {
            $headers['Strict-Transport-Security'] = "max-age={$this->hstsMaxAge}; includeSubDomains";
        }

        return $response->withHeaders($headers);
    }
}
