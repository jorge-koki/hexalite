<?php

declare(strict_types=1);

namespace HexaLite\Tests\Http;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Route;
use HexaLite\Container\Container;
use HexaLite\Http\Response;
use HexaLite\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function makeRouter(): Router
    {
        return new Router(
            controllers:  [FixturePingController::class],
            container:    new Container(),
            cacheFile:    sys_get_temp_dir() . '/hexalite-test-routes-never-written.php',
            isProduction: false,   // sin caché: escanea siempre
        );
    }

    public function testScansStaticRoute(): void
    {
        $routes = $this->makeRouter()->getRoutesInfo();

        $ping = $this->findRoute($routes, 'GET', '/api/ping');

        $this->assertNotNull($ping, 'Debe registrarse la ruta estática GET /api/ping');
        $this->assertSame(FixturePingController::class, $ping['controller']);
        $this->assertSame('ping', $ping['action']);
    }

    public function testScansDynamicRoute(): void
    {
        $routes = $this->makeRouter()->getRoutesInfo();

        $user = $this->findRoute($routes, 'GET', '/api/users/{id}');

        $this->assertNotNull($user, 'Debe registrarse la ruta dinámica GET /api/users/{id}');
        $this->assertSame('user', $user['action']);
    }

    public function testGetInstanceReturnsLastRouter(): void
    {
        $router = $this->makeRouter();

        $this->assertSame($router, Router::getInstance());
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, mixed>|null
     */
    private function findRoute(array $routes, string $method, string $path): ?array
    {
        foreach ($routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return $route;
            }
        }

        return null;
    }
}

// ── Fixture controller ───────────────────────────────────────────────────────

#[Controller('/api')]
final class FixturePingController
{
    #[Route('/ping', method: 'GET')]
    public function ping(): Response
    {
        return response(['pong' => true]);
    }

    #[Route('/users/{id}', method: 'GET')]
    public function user(int $id): Response
    {
        return response(['id' => $id]);
    }
}
