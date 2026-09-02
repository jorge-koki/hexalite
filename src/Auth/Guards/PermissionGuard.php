<?php

declare(strict_types=1);

namespace HexaLite\Auth\Guards;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\JwtPayload;
use HexaLite\Http\Domain\GuardInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * Deja pasar solo si el usuario cumple TODOS los requisitos del atributo
 * #[Permission(...)]. Los permisos son la unión de los de sus roles más los
 * asignados directamente, según {@see UserRepositoryInterface}.
 *
 * Cada requisito puede ser un permiso simple o un grupo de ALTERNATIVAS
 * separadas por `|`: `'ver:vehiculo|ver:cotizacion'` se cumple teniendo
 * cualquiera de los dos. Es lo que hace usable un recurso de referencia que
 * comparten varios flujos —un selector de vehículos que abren tanto el
 * cotizador como el constructor de listas de precios— sin tener que inventar un
 * permiso nuevo para cada combinación.
 *
 * Con un rol superusuario configurado (`AUTH_SUPER_ROLE`), quien lo tenga pasa
 * sin revisar permisos puntuales.
 */
final class PermissionGuard implements GuardInterface
{
    /**
     * @param string $superRole Rol con acceso total. '' (por defecto) desactiva el
     *                          atajo y evita la consulta extra de roles.
     */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly string $superRole = '',
    ) {
    }

    public function canActivate(Request $request): bool|Response
    {
        /** @var string[] $required */
        $required = (array) ($request->getAttribute('_guard_permissions') ?? []);

        // Cada requisito es un grupo de alternativas; los vacíos ('', '|') no
        // exigen nada y se descartan aquí, no dentro del bucle de comprobación.
        $groups = [];
        foreach ($required as $requirement) {
            $alternatives = array_values(array_filter(
                array_map('trim', explode('|', (string) $requirement)),
                static fn(string $name): bool => $name !== '',
            ));

            if ($alternatives !== []) {
                // Lista de pares, no mapa: una clave como '123' se convertiría en
                // int y el nombre del permiso saldría en `missing` con otro tipo.
                $groups[] = [(string) $requirement, $alternatives];
            }
        }

        if ($groups === []) {
            return true;
        }

        $user = $request->getAttribute('user');
        if (!$user instanceof JwtPayload || $user->sub <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        // El atajo del superusuario va PRIMERO: si acierta, ahorra la consulta de
        // permisos efectivos, que es la cara de las dos.
        if ($this->superRole !== '' && in_array($this->superRole, $this->users->getRoleNames($user->sub), true)) {
            return true;
        }

        $granted = $this->users->getEffectivePermissions($user->sub);
        $missing = [];

        foreach ($groups as [$requirement, $alternatives]) {
            if (array_intersect($alternatives, $granted) === []) {
                $missing[] = $requirement;
            }
        }

        if ($missing !== []) {
            return Response::json([
                'error'   => 'Forbidden',
                'message' => 'No tienes permiso para esta acción.',
                // Decir CUÁL falta es útil para el desarrollador del front y no
                // filtra nada: quien pregunta ya está autenticado.
                'missing' => $missing,
            ], 403);
        }

        return true;
    }
}
