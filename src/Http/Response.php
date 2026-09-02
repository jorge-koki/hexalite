<?php

namespace HexaLite\Http;

use JsonException;

class Response
{
    private array $cookiesQueue = [];

    public function __construct(
        private mixed $content = '',
        private int $statusCode = 200,
        private array $headers = []
    ) {
    }

    public function withCookie(string $name, string $value, array $options = []): self
    {
        $secureDefault = $options['secure'] ?? (getenv('APP_ENV') === 'production');
        $domain = $options['domain'] ?? '';
        $path   = $options['path'] ?? '/';

        // La clave incluye dominio+path (no solo el nombre) para poder emitir DOS
        // cookies con el MISMO nombre y distinto dominio en una sola respuesta —
        // p.ej. BORRAR una variante host-only vieja y FIJAR la de dominio
        // `.fletcot.com`. Con clave solo-por-nombre, la segunda pisaba a la primera.
        $this->cookiesQueue[$name . '|' . $domain . '|' . $path] = [
            'name'    => $name,
            'value'   => $value,
            'expires' => $options['expires'] ?? 0,
            'path'    => $path,
            'domain'  => $domain,
            // Por defecto en desarrollo NO marcamos secure para facilitar testing en http://localhost
            'secure'   => $secureDefault,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? 'Strict',
        ];

        return $this;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $content = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            return new self(
                $content,
                $status,
                ['Content-Type' => 'application/json; charset=utf-8']
            );

        } catch (JsonException $je) {
            // Log minimal info and return a minimal 500 response to avoid leaking internal state
            error_log('JSON encoding error: ' . $je->getMessage());
            return new self(
                '{"error":"JSON encoding error"}',
                500,
                ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        #Cookies
        foreach ($this->cookiesQueue as $c) {
            setcookie($c['name'], $c['value'], [
                'expires'  => $c['expires'],
                'path'     => $c['path'],
                'domain'   => $c['domain'],
                'secure'   => $c['secure'],
                'httponly' => $c['httponly'],
                'samesite' => $c['samesite']
            ]);
        }

        # Headers personalizados
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }

        # Contenido
        # Si no es string, asumimos que es JSON y no se ha procesado aún
        if (
            !\is_string($this->content) &&
            (
                \is_array($this->content) ||
                \is_object($this->content)
            )
        ) {
            # Verificamos si ya existe el header para no duplicarlo
            if (!isset($this->headers['Content-Type'])) {
                header('Content-Type: application/json; charset=utf-8');
            }
            try {
                echo json_encode($this->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $e) {
                # Fallback de emergencia
                http_response_code(500);
                echo '{"error":"Internal JSON Error"}';
            }
        } else {
            # Si ya es string (ej. HTML o JSON pre-codificado por Response::json)
            echo $this->content;
        }
    }
    /**
     * Devuelve una nueva instancia con headers adicionales
     *
     * IMPORTANTE: preservamos la cola de cookies para que middlewares
     * que devuelvan una nueva instancia no eliminen las cookies encoladas.
     */
    public function withHeaders(array $headers): self
    {
        $new = new self(
            $this->content,
            $this->statusCode,
            [...$this->headers, ...$headers]
        );

        // Copiar la cola de cookies para no perder cookies en middlewares
        $new->cookiesQueue = $this->cookiesQueue;

        return $new;
    }

    /**
     * Obtiene los headers actuales
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Obtiene el contenido
     */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Obtiene el código de estado
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Cookies encoladas, indexadas por nombre (la última gana si se encoló la
     * misma varias veces con distinto dominio). Pensado para tests y para
     * runtimes que envían la respuesta ellos mismos en vez de usar send().
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCookies(): array
    {
        $cookies = [];
        foreach ($this->cookiesQueue as $cookie) {
            $cookies[$cookie['name']] = $cookie;
        }

        return $cookies;
    }
}
