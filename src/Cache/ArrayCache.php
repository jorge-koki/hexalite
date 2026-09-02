<?php

declare(strict_types=1);

namespace HexaLite\Cache;

/**
 * Caché en memoria del proceso. Es el fallback cuando no hay Redis configurado.
 *
 * OJO con las expectativas: bajo PHP-FPM cada worker tiene su propia copia y todo
 * se pierde al terminar la petición (modelo share-nothing). Sirve para no tener
 * que poner condicionales por todos lados, y para tests; NO es un caché compartido.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: string, expires: int}> */
    private array $store = [];

    public function get(string $key): ?string
    {
        $entry = $this->store[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] !== 0 && $entry['expires'] <= time()) {
            unset($this->store[$key]);
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, string $value, int $ttl = 0): void
    {
        // ttl 0 = sin expiración; cualquier otro valor se suma a "ahora" (uno
        // negativo deja la entrada ya vencida, que es lo que esperan los tests
        // y quien quiera invalidar sin borrar).
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl !== 0 ? time() + $ttl : 0,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function increment(string $key, int $ttl = 0): int
    {
        $current = (int) ($this->get($key) ?? 0);
        $next    = $current + 1;

        // El TTL solo se fija al crear la clave (ventana fija, no deslizante).
        $expires = $current === 0 && $ttl !== 0
            ? time() + $ttl
            : ($this->store[$key]['expires'] ?? 0);

        $this->store[$key] = ['value' => (string) $next, 'expires' => $expires];

        return $next;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /** Vacía el caché (útil en tests). */
    public function flush(): void
    {
        $this->store = [];
    }
}
