<?php

declare(strict_types=1);

namespace HexaLite\Auth\Middlewares;

use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Protección CSRF por double-submit cookie.
 *
 * En una petición autenticada el navegador manda SIEMPRE la cookie `csrf_token`
 * —también si el que dispara la petición es un sitio atacante—, pero ese sitio
 * NO puede leerla para copiarla en la cabecera `X-CSRF-TOKEN`. Por eso, cuando
 * la cookie está presente, exigimos que la cabecera coincida.
 *
 * La exigencia se activa SOLO si existe la cookie, es decir, solo si hay sesión.
 * Así el middleware puede ser global sin romper las rutas públicas (login,
 * registro, forgot-password), que todavía no tienen cookie ni sesión que abusar.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param string[] $exemptPaths Rutas cuyo secreto viaja en el CUERPO, no en la
     *        cookie: el token que se mandó por correo. Ahí el double-submit no
     *        protege nada —el atacante no conoce ese token— y en cambio estorba:
     *        quien abre el enlace en el navegador donde ya tiene sesión trae la
     *        cookie CSRF, se le exige una cabecera que la pantalla pública no
     *        manda, y el enlace bueno se ve como "no válido".
     */
    public function __construct(
        private readonly array $exemptPaths = ['/auth/verify-email', '/auth/reset-password'],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        if (in_array($request->getPath(), $this->exemptPaths, true)) {
            return $next($request);
        }

        $cookieToken = $request->cookie('csrf_token');
        if (!$cookieToken) {
            return $next($request);
        }

        $headerToken = $request->getHeader('X-CSRF-TOKEN');
        if (!is_string($headerToken) || !hash_equals($cookieToken, $headerToken)) {
            return Response::json([
                'error'   => 'CSRF token mismatch',
                'message' => 'Falta la cabecera X-CSRF-TOKEN o no coincide con la cookie csrf_token.',
            ], 403);
        }

        return $next($request);
    }
}
