# API — Caché

[← Índice de la referencia](README.md)

- [`CacheInterface`](#cacheinterface) · [`CacheFactory`](#cachefactory) · [`RedisCache`](#rediscache) · [`ArrayCache`](#arraycache)

---

## CacheInterface

`HexaLite\Cache\CacheInterface` — `src/Cache/CacheInterface.php`

Caché de clave/valor deliberadamente mínima: solo lo que el framework necesita
(revocación de tokens, contadores de *throttle*).

```php
get(string $key): ?string
set(string $key, string $value, int $ttl = 0): void
delete(string $key): void
increment(string $key, int $ttl = 0): int
isAvailable(): bool
```

| Método | Contrato |
|---|---|
| `get()` | El valor, o `null` si no existe, expiró **o el backend no responde**. |
| `set()` | TTL en segundos; `0` = sin expiración. |
| `increment()` | Incrementa y devuelve el valor nuevo. **El TTL se aplica solo al crear la clave**, para no reiniciar la ventana en cada incremento — es lo que hace correcto un rate limit de ventana fija. |
| `isAvailable()` | ¿El backend está operativo? Permite degradar a otra estrategia. |

**Las implementaciones nunca lanzan por un fallo del backend.** Un caché caído degrada,
no rompe.

---

## CacheFactory

`HexaLite\Cache\CacheFactory` — `src/Cache/CacheFactory.php`

```php
static fromEnv(): CacheInterface
static redisRequested(): bool
static redisConfigFromEnv(): ?array
```

`fromEnv()` devuelve un [`RedisCache`](#rediscache) si el entorno lo pide **y** hay
cliente instalado; en cualquier otro caso, un [`ArrayCache`](#arraycache).

`redisRequested()` dice si el entorno pide Redis aunque no haya cliente — sirve para
avisar al arrancar de que la configuración no se está cumpliendo.

| Variable | Efecto |
|---|---|
| `REDIS_URL` | `redis://[:password@]host[:port][/db]`. Tiene prioridad sobre el resto. |
| `REDIS_HOST` | Activa Redis por sí sola. |
| `REDIS_PORT` | `6379` por defecto. |
| `REDIS_PASSWORD` | `AUTH`, opcional. |
| `REDIS_DB` | Índice de base de datos, `0` por defecto. |
| `REDIS_PREFIX` | Prefijo de claves, opcional. |

---

## RedisCache

`HexaLite\Cache\RedisCache` — `src/Cache/RedisCache.php`

```php
__construct(
    string  $host     = '127.0.0.1',
    int     $port     = 6379,
    ?string $password = null,
    int     $database = 0,
    string  $prefix   = '',
    float   $timeout  = 1.5,
)

static clientAvailable(): bool
client(): ?object
```

Habla con **cualquiera de los dos clientes habituales**: la extensión `ext-redis`
(phpredis) o el paquete `predis/predis`. Se elige el que esté disponible, con phpredis
primero por ser más rápido. `clientAvailable()` dice si hay alguno instalado.

**La conexión es perezosa y no se reintenta.** No se toca Redis hasta la primera
operación; si no responde, la instancia se marca como no disponible y todas las
llamadas pasan a ser no-ops silenciosas. `get()` devuelve `null`, `increment()`
devuelve `0`, `set()` y `delete()` no hacen nada. Un Redis caído nunca debe tumbar la
petición; quien necesite saberlo tiene `isAvailable()`.

`client()` expone el cliente nativo ya conectado, por si hace falta una operación que
la interfaz no cubre.

El `prefix` aísla aplicaciones que comparten una misma instancia de Redis.

---

## ArrayCache

`HexaLite\Cache\ArrayCache` — `src/Cache/ArrayCache.php`

```php
flush(): void   // vacía el caché; útil en tests
```

Caché en memoria del proceso. Es el respaldo cuando no hay Redis configurado.

**Cuidado con las expectativas:** bajo PHP-FPM cada worker tiene su propia copia y
todo se pierde al terminar la petición. Sirve para no tener que poner condicionales
por todos lados, y para los tests; **no es un caché compartido**. En particular, un
rate limit apoyado en él es prácticamente inservible como defensa en producción.

Un TTL negativo deja la entrada ya vencida — es la forma de invalidar sin borrar.
