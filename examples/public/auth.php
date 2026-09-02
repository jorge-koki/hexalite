<?php

declare(strict_types=1);

/**
 * Ejemplo ejecutable del KIT DE AUTENTICACIÓN de HexaLite.
 *
 * Preparación (una vez):
 *
 *   1. Crea el esquema en tu base de datos:
 *        psql "$DATABASE_URL" -f src/Auth/migrations/auth_pgsql.sql
 *        # o: mysql -u user -p base < src/Auth/migrations/auth_mysql.sql
 *
 *      Y, si quieres arrancar con un administrador ya creado (PostgreSQL):
 *        psql "$DATABASE_URL" -f src/Auth/migrations/seed_user_pgsql.sql
 *        # → admin@example.com / CambiaEsto1!
 *
 *   2. Copia `.env.example` a `.env` y rellena, como mínimo:
 *        DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
 *        JWT_SECRET y JWT_REFRESH_SECRET (distintas entre sí):
 *          php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 *
 * Arranque:
 *
 *   php -S localhost:8000 -t examples/public examples/public/auth.php
 *
 * Prueba (‑c/‑b guardan y reenvían las cookies, como haría un navegador):
 *
 *   curl -c c.txt -X POST localhost:8000/auth/register -H 'Content-Type: application/json' \
 *        -d '{"name":"Ada","email":"ada@example.com","password":"Contra5ena!","password_confirmation":"Contra5ena!"}'
 *
 *   curl -b c.txt localhost:8000/auth/me
 *   curl -b c.txt localhost:8000/admin/me        # rutas protegidas del ejemplo
 *   curl -b c.txt localhost:8000/auth/health
 *
 * La respuesta del registro incluye `verification_url` porque APP_ENV no es
 * production; ábrela o postea su token a /auth/verify-email.
 */

use HexaLite\Auth\AuthServiceProvider;
use HexaLite\Examples\Controllers\AdminController;
use HexaLite\Container\Container;
use HexaLite\Http\Middlewares\CorsMiddleware;
use HexaLite\Http\Request;
use HexaLite\Http\Router;

require __DIR__ . '/../../vendor/autoload.php';

loadEnv(__DIR__ . '/../../.env');

$isProduction = env('APP_ENV', 'local') === 'production';

// 1) Contenedor.
$container = new Container(
    cacheFile:    __DIR__ . '/../var/cache/container.php',
    isProduction: $isProduction,
);

// 2) El provider registra TODO el kit leyendo el entorno: conexiones de base de
//    datos, caché (Redis si está configurado), mailer, JWT, política de
//    contraseñas, guards y el controlador de auth. Nada se instancia hasta que
//    se pide, así que esta llamada no abre ninguna conexión.
(new AuthServiceProvider($container))->register();

// 3) Router. Los controladores y atributos-guard del kit los publica el propio
//    provider; AdminController es el ejemplo de rutas protegidas por rol/permiso.
$router = new Router(
    controllers:     [...AuthServiceProvider::controllers(), AdminController::class],
    container:       $container,
    cacheFile:       __DIR__ . '/../var/cache/routes.php',
    isProduction:    $isProduction,
    guardAttributes: AuthServiceProvider::guardAttributes(),
);

// 4) CORS. Con FRONTEND_ORIGINS definido se habilitan las cookies para esos
//    orígenes; sin él, modo abierto SIN credenciales (lo único seguro por defecto).
$container->set(CorsMiddleware::class, CorsMiddleware::fromEnv());
$router->addGlobalMiddleware(CorsMiddleware::class, priority: 1);

// 5) Comprobaciones de arranque (avisa si en producción falta el mailer).
(new AuthServiceProvider($container))->boot();

if (!$isProduction) {
    $router->printRoutes();
}

$router->dispatch(Request::createFromGlobals($container));
