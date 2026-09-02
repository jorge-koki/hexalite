<?php

declare(strict_types=1);

namespace HexaLite\Tests\Cache;

use HexaLite\Cache\ArrayCache;
use HexaLite\Cache\CacheFactory;
use HexaLite\Cache\RedisCache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private const REDIS_VARS = ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_DB', 'REDIS_PREFIX'];

    protected function setUp(): void
    {
        foreach (self::REDIS_VARS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testArrayCacheStoresAndReads(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', 'v');

        $this->assertSame('v', $cache->get('k'));
        $this->assertNull($cache->get('ausente'));
    }

    public function testArrayCacheRespectsExpiration(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', 'v', -1);

        $this->assertNull($cache->get('k'));
    }

    public function testIncrementCounts(): void
    {
        $cache = new ArrayCache();

        $this->assertSame(1, $cache->increment('hits', 60));
        $this->assertSame(2, $cache->increment('hits', 60));
        $this->assertSame(3, $cache->increment('hits', 60));
    }

    public function testIncrementFixesTheWindowOnTheFirstHit(): void
    {
        $cache = new ArrayCache();

        // La ventana se fija en el PRIMER incremento; los siguientes no la tocan.
        // Aquí el segundo pasa un TTL ya vencido: si el TTL se reaplicara, la
        // clave moriría al instante y el contador volvería a empezar — que es
        // justo el fallo que permitiría pegar sin límite.
        $cache->increment('window', 3600);
        $this->assertSame(2, $cache->increment('window', -1));
        $this->assertSame('2', $cache->get('window'));
    }

    public function testFactoryFallsBackToArrayCacheWithoutRedis(): void
    {
        $this->assertInstanceOf(ArrayCache::class, CacheFactory::fromEnv());
        $this->assertFalse(CacheFactory::redisRequested());
    }

    public function testFactoryReadsRedisHost(): void
    {
        putenv('REDIS_HOST=127.0.0.1');
        $_ENV['REDIS_HOST'] = '127.0.0.1';

        $this->assertTrue(CacheFactory::redisRequested());

        $config = CacheFactory::redisConfigFromEnv();
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(6379, $config['port']);
    }

    public function testFactoryParsesRedisUrl(): void
    {
        putenv('REDIS_URL=redis://:secreto@cache.local:6380/3');
        $_ENV['REDIS_URL'] = 'redis://:secreto@cache.local:6380/3';

        $config = CacheFactory::redisConfigFromEnv();

        $this->assertSame('cache.local', $config['host']);
        $this->assertSame(6380, $config['port']);
        $this->assertSame('secreto', $config['password']);
        $this->assertSame(3, $config['database']);
    }

    public function testRedisCacheDegradesSilentlyWhenUnreachable(): void
    {
        // Puerto donde no hay nada escuchando: la caché no debe lanzar, solo
        // comportarse como si estuviera vacía.
        $cache = new RedisCache('127.0.0.1', 1, timeout: 0.05);

        $this->assertFalse($cache->isAvailable());
        $this->assertNull($cache->get('k'));
        $this->assertSame(0, $cache->increment('k', 60));

        $cache->set('k', 'v');
        $cache->delete('k');
    }
}
