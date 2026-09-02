<?php

declare(strict_types=1);

namespace HexaLite\Auth\Guards;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\JwtPayload;
use HexaLite\Http\Domain\GuardInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Deja pasar si el usuario tiene AL MENOS UNO de los roles del atributo
 * #[Roles(...)]. Ver {@see PermissionGuard} para el control fino.
 */
final class RoleGuard implements GuardInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function canActivate(Request $request): bool|Response
    {
        /** @var string[] $required */
        $required = (array) ($request->getAttribute('_guard_roles') ?? []);
        if ($required === []) {
            return true;
        }

        $user = $request->getAttribute('user');
        if (!$user instanceof JwtPayload || $user->sub <= 0) {
            // 401, no 403: no es que le falten permisos, es que no hay sesión.
            // Distinguirlos permite al front redirigir al login en vez de mostrar
            // un "no tienes acceso" a alguien que solo tenía la sesión vencida.
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $roles = $this->users->getRoleNames($user->sub);
        if (array_intersect($required, $roles) === []) {
            return Response::json([
                'error'   => 'Forbidden',
                'message' => 'No tienes el rol necesario para esta acción.',
            ], 403);
        }

        return true;
    }
}
