<?php

declare(strict_types=1);

/**
 * Ejemplo ejecutable de HexaLite.
 *
 * Arráncalo desde la raíz del paquete con:
 *
 *   composer install
 *   php -S localhost:8000 -t examples/public
 *
 * y prueba:
 *
 *   curl localhost:8000/
 *   curl localhost:8000/hello/ada
 *   curl localhost:8000/users
 *   curl localhost:8000/users/1
 *   curl -X POST localhost:8000/users -H 'Content-Type: application/json' -d '{"name":"Grace","email":"grace@example.com"}'
 *   curl -X POST localhost:8000/users -H 'Content-Type: application/json' -d '{"name":"x"}'   # → 422
 */

use HexaLite\Container\Container;
use HexaLite\Http\Request;
use HexaLite\Http\Router;
use HexaLite\Examples\Controllers\HelloController;
use HexaLite\Examples\Controllers\UserController;
use HexaLite\Examples\Middlewares\RequestIdMiddleware;

require __DIR__ . '/../../vendor/autoload.php';

$isProduction = env('APP_ENV', 'local') === 'production';

// 1) Contenedor de inyección de dependencias
$container = new Container(
    cacheFile:    __DIR__ . '/../var/cache/container.php',
    isProduction: $isProduction,
);

// 2) Router: escanea los controladores buscando atributos #[Route]
$router = new Router(
    controllers:  [
        HelloController::class,
        UserController::class,
    ],
    container:    $container,
    cacheFile:    __DIR__ . '/../var/cache/routes.php',
    isProduction: $isProduction,
);

// 3) Middleware global de ejemplo (añade X-Request-Id a cada respuesta)
$router->addGlobalMiddleware(RequestIdMiddleware::class, priority: 1);

// 4) En desarrollo, imprime la tabla de rutas en la consola del servidor
if (!$isProduction) {
    $router->printRoutes();
}

// 5) Despacha la petición actual
$router->dispatch(Request::createFromGlobals($container));
