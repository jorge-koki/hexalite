<?php

declare(strict_types=1);

namespace HexaLite\Examples\Controllers;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Middleware;
use HexaLite\Attributes\Route;
use HexaLite\Auth\Attributes\Permission;
use HexaLite\Auth\Attributes\Roles;
use HexaLite\Auth\Attributes\Throttle;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Middlewares\AuthMiddleware;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Rutas PROTEGIDAS: qué se ve desde el otro lado de la sesión.
 *
 * Cada método enseña un candado distinto. El orden lo garantiza el Router y es
 * siempre el mismo: primero los MIDDLEWARES (el de auth valida la cookie y
 * publica al usuario) y después los GUARDS, que ya pueden mirar sus roles y
 * permisos. Una ruta con `#[Permission]` pero SIN el middleware de auth responde
 * 401 siempre, porque nadie publicó la sesión que el guard busca.
 *
 * Aquí el middleware va a nivel de CLASE, así cubre todos los métodos; ponerlo en
 * un método concreto funciona igual.
 *
 * Se prueba con el usuario que siembra `src/Auth/migrations/seed_user_pgsql.sql`
 * (admin@example.com / CambiaEsto1!), que llega con el rol `admin` y sus tres
 * permisos: users:read, users:write y users:delete.
 */
#[Controller('/admin')]
#[Middleware(AuthMiddleware::class)]
final class AdminController
{
    public function __construct(private UserRepositoryInterface $users) {}

    /**
     * Solo pide SESIÓN: cualquier usuario que haya entrado.
     *
     * `$request->user()` devuelve el JwtPayload que publicó el middleware. Nunca
     * hay que decodificar el token a mano en un controlador.
     */
    #[Route('/me', method: 'GET')]
    public function me(Request $request): Response
    {
        $user = $request->user();

        return response([
            'id'        => $user->sub,
            'roles'     => $this->users->getRoleNames($user->sub),
            'permisos'  => $this->users->getEffectivePermissions($user->sub),
        ]);
    }

    /** Exige el ROL `admin`: grueso, para zonas enteras de la API. */
    #[Route('/panel', method: 'GET')]
    #[Roles('admin')]
    public function panel(): Response
    {
        return response(['ok' => true, 'mensaje' => 'Estás en la zona de administración.']);
    }

    /** Exige un PERMISO concreto: fino, y no hay que tocarlo al crear roles nuevos. */
    #[Route('/usuarios', method: 'GET')]
    #[Permission('users:read')]
    public function index(): Response
    {
        return response(['data' => ['(aquí iría el listado)']]);
    }

    /**
     * ALTERNATIVAS con `|`: basta cumplir UNA.
     *
     * Es lo que necesita un recurso al que llegan varios flujos —el selector de
     * usuarios lo abren tanto quien edita como quien da de baja— sin inventar un
     * permiso nuevo para cada combinación.
     */
    #[Route('/usuarios/selector', method: 'GET')]
    #[Permission('users:write|users:delete')]
    public function selector(): Response
    {
        return response(['data' => ['(selector compartido por varios flujos)']]);
    }

    /**
     * Varios argumentos = se exigen TODOS. `reports:read` no existe en las
     * semillas, así que esta ruta responde 403 incluso al admin, con la lista de
     * lo que falta en `missing`. Es el ejemplo de cómo se ve un rechazo.
     */
    #[Route('/informes', method: 'GET')]
    #[Permission('users:read', 'reports:read')]
    public function reports(): Response
    {
        return response(['data' => ['(nunca llegas aquí sin conceder reports:read)']]);
    }

    /**
     * Límite de peticiones por IP y ruta: 5 cada 60 s. A la sexta responde 429
     * con `X-RateLimit-Limit/Count/Remaining` y `Retry-After`.
     *
     * NECESITA REDIS para contar de verdad. PHP no comparte nada entre
     * peticiones, así que el contador en memoria del fallback nace vacío en cada
     * una y el límite nunca se alcanza: sin `REDIS_HOST` esta ruta responde
     * siempre 200, por muchas veces que la llames. No es un fallo del ejemplo,
     * es lo que significa "share-nothing".
     */
    #[Route('/exportar', method: 'POST')]
    #[Permission('users:read')]
    #[Throttle(5, 60)]
    public function export(): Response
    {
        return response(['ok' => true, 'mensaje' => 'Exportación encolada.']);
    }
}
