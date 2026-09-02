<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\Guards\PermissionGuard;
use HexaLite\Auth\Services\JwtPayload;
use HexaLite\Http\Request;
use HexaLite\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * El repositorio en memoria da 'users:read' al rol `user` y
 * 'users:read' + 'users:write' al rol `admin`.
 */
final class PermissionGuardTest extends TestCase
{
    public function testAllowsWhenUserHasEveryPermission(): void
    {
        $guard = $this->guard(['admin']);

        $this->assertTrue($guard->canActivate($this->request(['users:read', 'users:write'])));
    }

    public function testDeniesWithTheMissingPermissionsListed(): void
    {
        $guard = $this->guard(['user']);

        $response = $guard->canActivate($this->request(['users:read', 'users:write']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['users:write'], $this->body($response)['missing']);
    }

    public function testAlternativesSeparatedByPipeNeedOnlyOne(): void
    {
        $guard = $this->guard(['user']);

        // Tiene users:read pero no users:write: el grupo se cumple igual.
        $this->assertTrue($guard->canActivate($this->request(['users:write|users:read'])));
    }

    public function testAlternativesFailWhenNoneIsGranted(): void
    {
        $guard = $this->guard(['user']);

        $response = $guard->canActivate($this->request(['users:write|users:delete']));

        $this->assertInstanceOf(Response::class, $response);
        // El requisito se reporta entero, no partido: es lo que hay que conceder.
        $this->assertSame(['users:write|users:delete'], $this->body($response)['missing']);
    }

    public function testSuperRoleSkipsThePermissionCheck(): void
    {
        $guard = $this->guard(['superadmin'], superRole: 'superadmin');

        // 'superadmin' no tiene NINGÚN permiso asignado y aun así pasa.
        $this->assertTrue($guard->canActivate($this->request(['users:delete'])));
    }

    public function testSuperRoleIsOffByDefault(): void
    {
        $guard = $this->guard(['superadmin']);

        $this->assertInstanceOf(Response::class, $guard->canActivate($this->request(['users:delete'])));
    }

    public function testUnauthenticatedGets401NotForbidden(): void
    {
        $guard = $this->guard(['admin']);

        $request = $this->request(['users:read'], authenticated: false);
        $response = $guard->canActivate($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRouteWithoutPermissionsIsPublic(): void
    {
        $guard = $this->guard(['user']);

        // Ni lista vacía ni requisitos en blanco exigen sesión.
        $this->assertTrue($guard->canActivate($this->request([], authenticated: false)));
        $this->assertTrue($guard->canActivate($this->request(['', ' | '], authenticated: false)));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param string[] $roles */
    private function guard(array $roles, string $superRole = ''): PermissionGuard
    {
        $users = new InMemoryUserRepository();
        $users->create('Ana', 'ana@example.com', 'hash', roles: $roles);

        return new PermissionGuard($users, $superRole);
    }

    /** @param string[] $permissions */
    private function request(array $permissions, bool $authenticated = true): Request
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], []);
        $request->setAttribute('_guard_permissions', $permissions);

        if ($authenticated) {
            $request->setAttribute('user', new JwtPayload(sub: 1));
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }
}
