<?php

declare(strict_types=1);

namespace HexaLite\Tests\Cache;

use HexaLite\Auth\Guards\ThrottleGuard;
use HexaLite\Cache\RedisCache;
use HexaLite\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Prueba la caché contra un Redis DE VERDAD. Se salta si no hay uno de pruebas,
 * para que la suite corra igual en una máquina sin Redis:
 *
 *   HEXALITE_TEST_REDIS_HOST=127.0.0.1
 *   HEXALITE_TEST_REDIS_PORT=56379
 *
 *   docker run -d --name hx-redis -p 56379:6379 redis:7-alpine
 */
final class RedisCacheIntegrationTest extends TestCase
{
    private RedisCache $cache;

    protected function setUp(): void
    {
        $host = getenv('HEXALITE_TEST_REDIS_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Sin Redis de pruebas (HEXALITE_TEST_REDIS_HOST no definido).');
        }
        if (!RedisCache::clientAvailable()) {
            $this->markTestSkipped('No hay cliente de Redis instalado (ext-redis o predis/predis).');
        }

        // Prefijo único por test: el mismo Redis puede estar compartido.
        $this->cache = new RedisCache(
            host:   $host,
            port:   (int) (getenv('HEXALITE_TEST_REDIS_PORT') ?: 6379),
            prefix: 'hxtest:' . bin2hex(random_bytes(6)) . ':',
        );

        if (!$this->cache->isAvailable()) {
            $this->markTestSkipped("Redis no responde en $host.");
        }
    }

    public function testSetGetDelete(): void
    {
        $this->cache->set('k', 'v', 60);
        $this->assertSame('v', $this->cache->get('k'));

        $this->cache->delete('k');
        $this->assertNull($this->cache->get('k'));
    }

    public function testMissingKeyIsNull(): void
    {
        // phpredis devuelve false y Predis null: el adaptador normaliza ambos.
        $this->assertNull($this->cache->get('nunca-escrita'));
    }

    public function testIncrementCounts(): void
    {
        $this->assertSame(1, $this->cache->increment('n', 60));
        $this->assertSame(2, $this->cache->increment('n', 60));
        $this->assertSame(3, $this->cache->increment('n', 60));
    }

    public function testExpiredKeyDisappears(): void
    {
        // TTL de 1 s: sirve para comprobar que el SETEX llega de verdad a Redis.
        $this->cache->set('efimera', 'v', 1);
        $this->assertSame('v', $this->cache->get('efimera'));

        sleep(2);
        $this->assertNull($this->cache->get('efimera'));
    }

    public function testThrottleGuardBlocksOverTheLimitWithSharedCounters(): void
    {
        $guard = new ThrottleGuard($this->cache);

        $request = static function (): Request {
            $r = new Request([], [], [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/auth/login',
                'REMOTE_ADDR'    => '203.0.113.7',
            ], []);
            $r->setAttribute('_guard_limit', 3);
            $r->setAttribute('_guard_ttl', 60);

            return $r;
        };

        $this->assertTrue($guard->canActivate($request()));
        $this->assertTrue($guard->canActivate($request()));
        $this->assertTrue($guard->canActivate($request()));
        // La cuarta ya supera el límite.
        $this->assertFalse($guard->canActivate($request()));

        // Un guard NUEVO ve el mismo contador: en Redis el límite es global entre
        // workers, que es justo lo que no da la caché en memoria.
        $this->assertFalse((new ThrottleGuard($this->cache))->canActivate($request()));
    }
}
