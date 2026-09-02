<?php

declare(strict_types=1);

namespace HexaLite\Examples\Middlewares;

use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Asigna un identificador único a cada petición y lo devuelve como header.
 * Ilustra el patrón antes/continúa/después de un middleware.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // ANTES: mutar la petición
        $id = bin2hex(random_bytes(8));
        $request->setAttribute('request_id', $id);

        // CONTINÚA: pasar al siguiente middleware / controlador
        $response = $next($request);

        // DESPUÉS: enriquecer la respuesta
        return $response->withHeaders(['X-Request-Id' => $id]);
    }
}
