<?php

declare(strict_types=1);

/**
 * Ejemplo ejecutable de ARQUITECTURA HEXAGONAL por módulos con HexaLite.
 *
 * Arráncalo desde la raíz del paquete —no necesita base de datos—:
 *
 *   composer install
 *   php -S localhost:8000 -t examples/public examples/public/hexagonal.php
 *
 * y sigue el flujo completo:
 *
 *   curl localhost:8000/informes?id_proyecto=1
 *
 *   curl -X POST localhost:8000/informes -H 'Content-Type: application/json' \
 *        -d '{"titulo":"Informe anual de seguridad","id_proyecto":1}'      # → 201
 *
 *   curl -X POST localhost:8000/informes -H 'Content-Type: application/json' \
 *        -d '{"titulo":"no","id_proyecto":1}'                              # → 422 (DTO)
 *
 *   curl -X POST localhost:8000/informes/1/publicar                        # → 200
 *   curl -X POST localhost:8000/informes/1/publicar                        # → 409 (regla del dominio)
 *   curl -X POST localhost:8000/informes/999/publicar                      # → 404 (regla del dominio)
 *
 * Para empezar de cero: rm examples/var/informes.json
 *
 * Para correrlo contra PostgreSQL o MySQL, aplica el esquema del módulo y define
 * el driver y la conexión en el entorno:
 *
 *   psql "$DATABASE_URL" -f examples/src/Modules/Informes/Infrastructure/Persistence/migrations/informes_pgsql.sql
 *   INFORMES_DRIVER=pdo DB_HOST=... DB_NAME=... DB_USER=... DB_PASSWORD=... \
 *     php -S localhost:8000 -t examples/public examples/public/hexagonal.php
 */

use HexaLite\Container\Container;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeNoEncontrado;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeYaPublicado;
use HexaLite\Examples\Modules\Informes\InformesServiceProvider;
use HexaLite\Http\Request;
use HexaLite\Http\Response;
use HexaLite\Http\Router;

require __DIR__ . '/../../vendor/autoload.php';

if (is_file(__DIR__ . '/../../.env')) {
    loadEnv(__DIR__ . '/../../.env');
}

$isProduction = env('APP_ENV', 'local') === 'production';

// 1) Contenedor.
$container = new Container(
    cacheFile:    __DIR__ . '/../var/cache/container.php',
    isProduction: $isProduction,
);

// 2) El provider del módulo enlaza el PUERTO con el ADAPTADOR elegido. Por
//    defecto, fichero JSON: el ejemplo corre sin base de datos y los datos
//    sobreviven entre peticiones, que es lo que hace falta para ver el 409.
$informes = new InformesServiceProvider(
    container: $container,
    driver:    env('INFORMES_DRIVER', InformesServiceProvider::DRIVER_FICHERO),
);
$informes->register();

// 3) Router. Los controladores del módulo los publica el propio provider, igual
//    que hace el kit de autenticación del framework.
$router = new Router(
    controllers:  InformesServiceProvider::controllers(),
    container:    $container,
    cacheFile:    __DIR__ . '/../var/cache/routes_hexagonal.php',
    isProduction: $isProduction,
);

// 4) Traducción de excepciones del DOMINIO a códigos HTTP.
//
//    Este es el detalle que mantiene limpio el hexágono: `InformeYaPublicado` no
//    sabe que existe el 409, y `Informe::publicar()` no importa nada de HTTP. La
//    decisión de qué código devolver es de la capa de entrada, y vive aquí —en el
//    borde—, no repartida por los controladores en bloques try/catch.
$router->registerExceptionHandler(
    InformeNoEncontrado::class,
    static fn (Throwable $e): Response => Response::json(
        ['error' => 'Request Failed', 'code' => 'INFORME_NO_ENCONTRADO', 'message' => $e->getMessage()],
        404,
    ),
);

$router->registerExceptionHandler(
    InformeYaPublicado::class,
    static fn (Throwable $e): Response => Response::json(
        ['error' => 'Request Failed', 'code' => 'INFORME_YA_PUBLICADO', 'message' => $e->getMessage()],
        409,
    ),
);

// 5) Comprobaciones de arranque (avisa si el driver no es apto para producción).
$informes->boot();

if (!$isProduction) {
    $router->printRoutes();
}

$router->dispatch(Request::createFromGlobals($container));
