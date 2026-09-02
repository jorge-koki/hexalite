<?php

declare(strict_types=1);

namespace HexaLite\Cache;

/**
 * Caché de clave/valor mínima. Deliberadamente pequeña: solo lo que el framework
 * necesita (revocación de tokens, contadores de throttle). Las implementaciones
 * NUNCA deben lanzar por un fallo del backend — un caché caído degrada, no rompe.
 */
interface CacheInterface
{
    /** Valor almacenado, o null si no existe / expiró / el backend no responde. */
    public function get(string $key): ?string;

    /** Guarda un valor con TTL en segundos (0 = sin expiración). */
    public function set(string $key, string $value, int $ttl = 0): void;

    public function delete(string $key): void;

    /**
     * Incrementa un contador y devuelve el valor nuevo. El TTL se aplica SOLO al
     * crear la clave, para no reiniciar la ventana en cada incremento (es lo que
     * hace correcto un rate-limit por ventana fija).
     */
    public function increment(string $key, int $ttl = 0): int;

    /**
     * ¿El backend está realmente operativo? Permite a quien la usa decidir si
     * degrada a otra estrategia (p. ej. leer de la BD en vez del caché).
     */
    public function isAvailable(): bool;
}
