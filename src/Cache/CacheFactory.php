<?php

declare(strict_types=1);

namespace HexaLite\Cache;

/**
 * Elige la implementación de caché según el entorno, con la misma filosofía que
 * {@see \HexaLite\Database\DatabaseManager::fromEnv()}: si llenas las variables
 * de Redis, se usa Redis; si no, se degrada a memoria del proceso y la app
 * funciona igual (solo sin caché compartido).
 *
 * Variables reconocidas:
 *   REDIS_URL       redis://[:password@]host[:port][/db]  (tiene prioridad)
 *   REDIS_HOST      host (activa Redis por sí sola)
 *   REDIS_PORT      6379 por defecto
 *   REDIS_PASSWORD  AUTH, opcional
 *   REDIS_DB        índice de base de datos, 0 por defecto
 *   REDIS_PREFIX    prefijo de claves, opcional
 */
final class CacheFactory
{
    /**
     * Devuelve un RedisCache si el entorno lo pide Y hay cliente instalado;
     * en cualquier otro caso, un ArrayCache.
     */
    public static function fromEnv(): CacheInterface
    {
        $config = self::redisConfigFromEnv();

        if ($config === null || !RedisCache::clientAvailable()) {
            return new ArrayCache();
        }

        return new RedisCache(
            host:     $config['host'],
            port:     $config['port'],
            password: $config['password'],
            database: $config['database'],
            prefix:   $config['prefix'],
        );
    }

    /** ¿El entorno pide Redis? (Aunque no haya cliente instalado.) */
    public static function redisRequested(): bool
    {
        return self::redisConfigFromEnv() !== null;
    }

    /**
     * Configuración de Redis leída del entorno, o null si no está configurado.
     *
     * @return array{host: string, port: int, password: ?string, database: int, prefix: string}|null
     */
    public static function redisConfigFromEnv(): ?array
    {
        $url = self::env('REDIS_URL');
        if ($url !== null) {
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host'])) {
                return null;
            }
            return [
                'host'     => $parts['host'],
                'port'     => (int) ($parts['port'] ?? 6379),
                'password' => $parts['pass'] ?? null,
                'database' => (int) ltrim((string) ($parts['path'] ?? ''), '/') ?: 0,
                'prefix'   => self::env('REDIS_PREFIX') ?? '',
            ];
        }

        $host = self::env('REDIS_HOST');
        if ($host === null) {
            return null;
        }

        return [
            'host'     => $host,
            'port'     => (int) (self::env('REDIS_PORT') ?? 6379),
            'password' => self::env('REDIS_PASSWORD'),
            'database' => (int) (self::env('REDIS_DB') ?? 0),
            'prefix'   => self::env('REDIS_PREFIX') ?? '',
        ];
    }

    /** Variable de entorno no vacía, o null. */
    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }
}
