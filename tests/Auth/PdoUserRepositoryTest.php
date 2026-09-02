<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\Infrastructure\Persistence\PdoUserRepository;
use HexaLite\Database\DatabaseInterface;
use HexaLite\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Prueba el repositorio contra bases de datos DE VERDAD, con el esquema de
 * `src/Auth/migrations/`. Las mismas aserciones corren en PostgreSQL y MySQL:
 * ahí es donde se cazan las diferencias de dialecto que un doble en memoria
 * jamás enseñaría.
 *
 * Cada motor se salta si no hay una base de pruebas configurada, para que la
 * suite siga corriendo en una máquina sin bases de datos:
 *
 *   HEXALITE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=hxtest'
 *   HEXALITE_TEST_PGSQL_USER=hx
 *   HEXALITE_TEST_PGSQL_PASSWORD=hx
 *
 *   HEXALITE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=53306;dbname=hxtest'
 *   HEXALITE_TEST_MYSQL_USER=root
 *   HEXALITE_TEST_MYSQL_PASSWORD=hx
 *
 * Levantar los motores para probar en local:
 *
 *   docker run -d --name hx-pg -e POSTGRES_PASSWORD=hx -e POSTGRES_USER=hx \
 *       -e POSTGRES_DB=hxtest -p 55432:5432 postgres:16-alpine
 *   docker run -d --name hx-my -e MYSQL_ROOT_PASSWORD=hx -e MYSQL_DATABASE=hxtest \
 *       -p 53306:3306 mysql:8
 */
final class PdoUserRepositoryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['pgsql' => ['pgsql'], 'mysql' => ['mysql']];
    }

    private function connect(string $driver): DatabaseInterface
    {
        $prefix = 'HEXALITE_TEST_' . strtoupper($driver);
        $dsn    = getenv("{$prefix}_DSN");

        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped("Sin base de pruebas de $driver ({$prefix}_DSN no definido).");
        }

        try {
            $db = DatabaseManager::make([
                'driver' => $driver,
                // El DSN ya viene completo; se pasa tal cual reconstruyendo el config.
                ...self::parseDsn($dsn, $driver),
                'username' => (string) (getenv("{$prefix}_USER") ?: ''),
                'password' => (string) (getenv("{$prefix}_PASSWORD") ?: ''),
            ]);
        } catch (Throwable $e) {
            $this->markTestSkipped("No se pudo conectar a $driver: " . $e->getMessage());
        }

        // Cada test arranca de cero. TRUNCATE ... CASCADE en PostgreSQL; en MySQL
        // hay que desactivar las FK un momento porque no admite CASCADE aquí.
        $tables = $driver === 'mysql'
            ? ['auth_user_roles', 'auth_users']
            : ['auth.user_roles', 'auth.users'];

        if ($driver === 'mysql') {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $db->query("TRUNCATE TABLE $table");
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $db->query('TRUNCATE TABLE ' . implode(', ', $tables) . ' CASCADE');
        }

        return $db;
    }

    /** @return array{host: string, port: int, database: string} */
    private static function parseDsn(string $dsn, string $driver): array
    {
        parse_str(str_replace(';', '&', substr($dsn, strlen($driver) + 1)), $parts);

        return [
            'host'     => (string) ($parts['host'] ?? '127.0.0.1'),
            'port'     => (int) ($parts['port'] ?? 0),
            'database' => (string) ($parts['dbname'] ?? ''),
        ];
    }

    private function repository(string $driver): PdoUserRepository
    {
        return new PdoUserRepository($this->connect($driver), $driver);
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[DataProvider('drivers')]
    public function testCreateAndFind(string $driver): void
    {
        $repo = $this->repository($driver);

        $user = $repo->create('Ada Lovelace', 'Ada@Example.com', 'hash', 'tok-verificacion', roles: ['user']);

        $this->assertNotNull($user->id);
        // El correo se normaliza a minúsculas al guardarlo.
        $this->assertSame('ada@example.com', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());

        // La búsqueda es insensible a mayúsculas en ambos motores.
        $this->assertSame($user->id, $repo->findByEmail('ADA@EXAMPLE.COM')?->id);
        $this->assertSame($user->id, $repo->findById((int) $user->id)?->id);
        $this->assertTrue($repo->emailExists('ada@example.com'));
        $this->assertFalse($repo->emailExists('nadie@example.com'));

        // El rol `user` de la semilla nace SIN permisos: es el fallo seguro para
        // quien se registra por su cuenta. Los permisos los reparte cada app.
        $this->assertSame(['user'], $repo->getRoleNames((int) $user->id));
        $this->assertSame([], $repo->getEffectivePermissions((int) $user->id));
    }

    #[DataProvider('drivers')]
    public function testAdminInheritsEveryPermissionFromItsRole(string $driver): void
    {
        $repo  = $this->repository($driver);
        $admin = $repo->create('Root', 'root@example.com', 'hash', roles: ['admin']);

        $permissions = $repo->getEffectivePermissions((int) $admin->id);

        $this->assertSame(['users:delete', 'users:read', 'users:write'], $permissions);
    }

    #[DataProvider('drivers')]
    public function testUnknownRoleIsIgnoredInsteadOfFailingTheSignup(string $driver): void
    {
        $repo = $this->repository($driver);

        // Un rol mal escrito en AUTH_DEFAULT_ROLES no debe impedir el alta: el
        // usuario se queda sin permisos, que es el fallo seguro.
        $user = $repo->create('Ada', 'ada@example.com', 'hash', roles: ['no-existe', 'user']);

        $this->assertSame(['user'], $repo->getRoleNames((int) $user->id));
    }

    #[DataProvider('drivers')]
    public function testDuplicateEmailIsRejectedByTheDatabase(string $driver): void
    {
        $repo = $this->repository($driver);
        $repo->create('Ada', 'ada@example.com', 'hash');

        // La garantía DURA contra carreras es el índice único (funcional en
        // PostgreSQL, por collation insensible en MySQL), no el pre-chequeo.
        $this->expectException(Throwable::class);
        $repo->create('Otra Ada', 'ADA@example.com', 'hash');
    }

    #[DataProvider('drivers')]
    public function testEmailVerificationIsSingleUse(string $driver): void
    {
        $repo = $this->repository($driver);
        $repo->create('Ada', 'ada@example.com', 'hash', 'token-abc');

        $result = $repo->verifyEmailByToken('token-abc');

        $this->assertNotNull($result);
        $this->assertSame('ada@example.com', $result['email']);
        $this->assertTrue($repo->findById($result['id'])?->hasVerifiedEmail());

        // El token se consumió: reabrir el enlace ya no vale.
        $this->assertNull($repo->verifyEmailByToken('token-abc'));
    }

    #[DataProvider('drivers')]
    public function testRefreshVerificationTokenReportsAlreadyVerified(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash', emailVerified: true);

        $result = $repo->refreshVerificationToken((int) $user->id, 'nuevo-token');

        $this->assertTrue($result['already_verified']);
        $this->assertNull($repo->verifyEmailByToken('nuevo-token'));
    }

    #[DataProvider('drivers')]
    public function testPasswordResetHappyPath(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash-viejo');

        $this->assertNotNull($repo->createPasswordResetToken((int) $user->id, 'reset-abc', 60));

        $result = $repo->resetPasswordByToken('reset-abc', 'hash-nuevo');

        $this->assertNotNull($result);
        $this->assertSame('hash-nuevo', $repo->findById((int) $user->id)?->password);
        // Single-use.
        $this->assertNull($repo->resetPasswordByToken('reset-abc', 'otro'));
    }

    #[DataProvider('drivers')]
    public function testExpiredResetTokenIsRejected(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash-viejo');

        // TTL negativo: el token nace vencido. La vigencia se comprueba en el
        // WHERE del UPDATE, así que esto valida la comparación de fechas en el
        // dialecto de cada motor.
        $repo->createPasswordResetToken((int) $user->id, 'reset-viejo', -10);

        $this->assertNull($repo->resetPasswordByToken('reset-viejo', 'hash-nuevo'));
        $this->assertSame('hash-viejo', $repo->findById((int) $user->id)?->password);
    }

    #[DataProvider('drivers')]
    public function testTokensValidAfterRoundTrip(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash');

        $this->assertSame(0, $repo->getTokensValidAfter((int) $user->id));

        $repo->setTokensValidAfter((int) $user->id, 1754000000);
        $this->assertSame(1754000000, $repo->getTokensValidAfter((int) $user->id));
    }

    #[DataProvider('drivers')]
    public function testGoogleLinkAndUnlink(string $driver): void
    {
        $repo = $this->repository($driver);
        $ada  = $repo->create('Ada', 'ada@example.com', 'hash');
        $bob  = $repo->create('Bob', 'bob@example.com', 'hash');

        $this->assertTrue($repo->linkGoogle((int) $ada->id, 'google-sub-1', true));
        $this->assertSame($ada->id, $repo->findByGoogleSub('google-sub-1')?->id);
        $this->assertTrue($repo->findById((int) $ada->id)?->hasVerifiedEmail());

        // El mismo Google no puede quedar en dos cuentas.
        $this->assertFalse($repo->linkGoogle((int) $bob->id, 'google-sub-1', false));

        $repo->unlinkGoogle((int) $ada->id);
        $this->assertNull($repo->findByGoogleSub('google-sub-1'));

        // Y liberado, ahora sí lo puede tomar otra cuenta.
        $this->assertTrue($repo->linkGoogle((int) $bob->id, 'google-sub-1', false));
    }

    #[DataProvider('drivers')]
    public function testGoogleOnlyAccountHasNoPassword(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', null, emailVerified: true, googleSub: 'sub-xyz');

        $this->assertNull($user->password);
        $this->assertFalse($user->verifyPassword('lo-que-sea'));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    #[DataProvider('drivers')]
    public function testUpdateProfileAndPassword(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash');

        $this->assertSame('Ada L.', $repo->updateProfile((int) $user->id, 'Ada L.')?->name);
        $this->assertTrue($repo->updatePassword((int) $user->id, 'hash-2'));
        $this->assertSame('hash-2', $repo->findById((int) $user->id)?->password);
        $this->assertFalse($repo->updatePassword(999999, 'hash-3'));
    }

    #[DataProvider('drivers')]
    public function testTouchLastLogin(string $driver): void
    {
        $repo = $this->repository($driver);
        $user = $repo->create('Ada', 'ada@example.com', 'hash');

        $this->assertNull($user->last_login_at);

        $repo->touchLastLogin((int) $user->id);
        $this->assertNotNull($repo->findById((int) $user->id)?->last_login_at);
    }

    #[DataProvider('drivers')]
    public function testHealth(string $driver): void
    {
        $this->assertSame('ok', $this->repository($driver)->health()['status']);
    }
}
