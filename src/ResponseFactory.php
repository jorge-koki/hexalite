<?php

declare(strict_types=1);

namespace HexaLite;

use HexaLite\Http\Response;

/**
 * Proxy para construir respuestas con sintaxis fluida: response()->json(...)
 * Métodos explícitos en vez de __call() para evitar overhead de magic methods.
 */
class ResponseFactory
{
    public function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    public function withCookie(string $name, string $value, array $options = []): Response
    {
        return (new Response())->withCookie($name, $value, $options);
    }

    public function withHeaders(array $headers): Response
    {
        return (new Response())->withHeaders($headers);
    }
}
