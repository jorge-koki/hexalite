<?php

declare(strict_types=1);

namespace HexaLite\Auth\Guards;

use HexaLite\Cache\ArrayCache;
use HexaLite\Cache\CacheInterface;
use HexaLite\Http\Domain\GuardInterface;
use HexaLite\Http\Request;

/**
 * Rate limit por IP + ruta, con ventana fija.
 *
 * El contador vive en el {@see CacheInterface} inyectado: con Redis el límite es
 * GLOBAL (todos los workers y todas las máquinas comparten la cuenta); con el
 * ArrayCache de fallback es por proceso y, bajo PHP-FPM, prácticamente
 * inservible como defensa — sirve para no romper en desarrollo, no para
 * protegerte en producción. Si te importa el límite, configura Redis.
 *
 * El Router publica los parámetros del atributo #[Throttle] como atributos de la
 * petición (`_guard_limit`, `_guard_ttl`) y lee de vuelta `_throttle_*` para
 * poner las cabeceras X-RateLimit-* en la respuesta 429.
 */
final class ThrottleGuard implements GuardInterface
{
    private CacheInterface $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache ?? new ArrayCache();
    }

    public function canActivate(Request $request): bool
    {
        $limit = (int) ($request->getAttribute('_guard_limit') ?? 60);
        $ttl   = (int) ($request->getAttribute('_guard_ttl') ?? 60);

        $key   = 'throttle:' . sha1($request->getIp() . '|' . $request->getPath());
        $count = $this->cache->increment($key, $ttl);

        $request->setAttribute('_throttle_count', $count);
        $request->setAttribute('_throttle_limit', $limit);
        $request->setAttribute('_throttle_ttl', $ttl);
        $request->setAttribute('_throttle_remaining', max(0, $limit - $count));

        // increment() devuelve 0 si el backend está caído. Dejar pasar es
        // deliberado: un Redis caído no debe tumbar la API entera.
        if ($count === 0) {
            return true;
        }

        return $count <= $limit;
    }
}
