<?php

declare(strict_types=1);

namespace HexaLite\Cache;

use Throwable;

/**
 * Caché sobre Redis que habla con CUALQUIERA de los dos clientes habituales:
 * la extensión `ext-redis` (phpredis) o el paquete `predis/predis`. Se elige el
 * que esté disponible, con phpredis primero por ser más rápido.
 *
 * La conexión es PEREZOSA: no se toca Redis hasta la primera operación. Si Redis
 * no responde, la instancia se marca como no disponible y todas las llamadas
 * pasan a ser no-ops silenciosas — un Redis caído nunca debe tumbar la petición.
 * Quien necesite saberlo tiene {@see isAvailable()}.
 */
final class RedisCache implements CacheInterface
{
    /** Cliente nativo (\Redis o \Predis\Client). null = aún sin conectar. */
    private ?object $client = null;

    private bool $connectionFailed = false;

    /**
     * @param string      $host     Host de Redis.
     * @param int         $port     Puerto.
     * @param string|null $password AUTH (null / '' = sin autenticación).
     * @param int         $database Número de base de datos (SELECT).
     * @param string      $prefix   Prefijo de todas las claves (aísla apps que comparten Redis).
     * @param float       $timeout  Timeout de conexión en segundos.
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly string $prefix = '',
        private readonly float $timeout = 1.5,
    ) {
    }

    /** ¿Hay algún cliente de Redis instalado en este proyecto? */
    public static function clientAvailable(): bool
    {
        return extension_loaded('redis') || class_exists(\Predis\Client::class);
    }

    public function isAvailable(): bool
    {
        return $this->connect() !== null;
    }

    public function get(string $key): ?string
    {
        $client = $this->connect();
        if ($client === null) {
            return null;
        }
        try {
            $value = $client->get($this->prefix . $key);
            // phpredis devuelve false y Predis null cuando la clave no existe.
            return ($value === false || $value === null) ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttl = 0): void
    {
        $client = $this->connect();
        if ($client === null) {
            return;
        }
        try {
            if ($ttl > 0) {
                $client->setex($this->prefix . $key, $ttl, $value);
            } else {
                $client->set($this->prefix . $key, $value);
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    public function delete(string $key): void
    {
        $client = $this->connect();
        if ($client === null) {
            return;
        }
        try {
            $client->del($this->prefix . $key);
        } catch (Throwable) {
            // best-effort
        }
    }

    public function increment(string $key, int $ttl = 0): int
    {
        $client = $this->connect();
        if ($client === null) {
            return 0;
        }
        try {
            $full = $this->prefix . $key;
            $new  = (int) $client->incr($full);

            // El TTL se fija SOLO en la primera petición de la ventana; si se
            // renovara en cada incremento la ventana nunca cerraría y el límite
            // se volvería infinito para quien siga pegando.
            if ($new === 1 && $ttl > 0) {
                $client->expire($full, $ttl);
            }
            return $new;
        } catch (Throwable) {
            return 0;
        }
    }

    /** Cliente nativo ya conectado, por si hace falta una operación avanzada. */
    public function client(): ?object
    {
        return $this->connect();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Conecta una sola vez. Devuelve null si no se puede (y no reintenta). */
    private function connect(): ?object
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if ($this->connectionFailed) {
            return null;
        }
        $this->connectionFailed = true; // pesimista: solo se revierte si todo sale bien

        try {
            if (extension_loaded('redis')) {
                $client = new \Redis();
                $client->connect($this->host, $this->port, $this->timeout);
                if ($this->password !== null && $this->password !== '') {
                    $client->auth($this->password);
                }
                if ($this->database !== 0) {
                    $client->select($this->database);
                }
            } elseif (class_exists(\Predis\Client::class)) {
                $params = [
                    'scheme'  => 'tcp',
                    'host'    => $this->host,
                    'port'    => $this->port,
                    'timeout' => $this->timeout,
                ];
                if ($this->password !== null && $this->password !== '') {
                    $params['password'] = $this->password;
                }
                if ($this->database !== 0) {
                    $params['database'] = $this->database;
                }
                $client = new \Predis\Client($params);
                $client->connect();
            } else {
                return null;
            }

            $client->ping();
        } catch (Throwable) {
            return null;
        }

        $this->connectionFailed = false;
        return $this->client = $client;
    }
}
