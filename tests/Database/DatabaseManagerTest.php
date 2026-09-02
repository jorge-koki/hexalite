<?php

declare(strict_types=1);

namespace HexaLite\Tests\Database;

use HexaLite\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseManagerTest extends TestCase
{
    /** @var string[] Variables tocadas por el test, para restaurar el entorno. */
    private const TOUCHED = [
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CONNECTION', 'DB_DRIVER',
        'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_NAME', 'MYSQL_USER', 'MYSQL_PASSWORD',
        'PGSQL_HOST', 'PGSQL_NAME', 'POSTGRES_HOST', 'POSTGRES_NAME',
    ];

    protected function setUp(): void
    {
        foreach (self::TOUCHED as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function env(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }

    public function testNoEnvironmentMeansNoConnections(): void
    {
        $manager = DatabaseManager::fromEnv();

        $this->assertTrue($manager->isEmpty());
        $this->assertNull($manager->defaultName());
    }

    public function testDbGroupCreatesTheDefaultConnection(): void
    {
        $this->env(['DB_HOST' => 'db.local', 'DB_NAME' => 'app', 'DB_USER' => 'u', 'DB_PASSWORD' => 'p']);

        $manager = DatabaseManager::fromEnv();

        $this->assertSame(['default'], $manager->names());
        $this->assertSame('default', $manager->defaultName());
        $this->assertSame('pgsql', $manager->config('default')['driver']);
    }

    public function testIncompleteGroupIsIgnored(): void
    {
        // Host sin base de datos: el grupo NO está lleno, así que no se activa.
        $this->env(['DB_HOST' => 'db.local']);

        $this->assertTrue(DatabaseManager::fromEnv()->isEmpty());
    }

    public function testDriverIsInferredFromThePort(): void
    {
        $this->env(['DB_HOST' => 'db.local', 'DB_NAME' => 'app', 'DB_PORT' => '3306']);

        $this->assertSame('mysql', DatabaseManager::fromEnv()->config('default')['driver']);
    }

    public function testMysqlAndPostgresGroupsCoexist(): void
    {
        $this->env([
            'DB_HOST'      => 'pg.local',  'DB_NAME'      => 'main',
            'MYSQL_HOST'   => 'my.local',  'MYSQL_NAME'   => 'legacy',
            'PGSQL_HOST'   => 'pg2.local', 'PGSQL_NAME'   => 'reporting',
        ]);

        $manager = DatabaseManager::fromEnv();

        $this->assertSame(['default', 'pgsql', 'mysql'], $manager->names());
        $this->assertSame('mysql', $manager->config('mysql')['driver']);
        $this->assertSame('legacy', $manager->config('mysql')['database']);
        $this->assertSame('reporting', $manager->config('pgsql')['database']);
        // La conexión sin nombre sigue siendo la del grupo DB_*.
        $this->assertSame('default', $manager->defaultName());
    }

    public function testPostgresPrefixIsAnAcceptedAlias(): void
    {
        $this->env(['POSTGRES_HOST' => 'pg.local', 'POSTGRES_NAME' => 'app']);

        $this->assertSame(['pgsql'], DatabaseManager::fromEnv()->names());
    }

    public function testFirstDiscoveredConnectionBecomesTheDefault(): void
    {
        $this->env(['MYSQL_HOST' => 'my.local', 'MYSQL_NAME' => 'app']);

        $this->assertSame('mysql', DatabaseManager::fromEnv()->defaultName());
    }

    public function testUnknownConnectionNameFails(): void
    {
        $this->expectException(RuntimeException::class);
        (new DatabaseManager())->connection('inexistente');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function dsnCases(): array
    {
        return [
            'pgsql' => [
                ['driver' => 'pgsql', 'host' => 'h', 'port' => 5432, 'database' => 'app'],
                'pgsql:host=h;port=5432;dbname=app',
            ],
            'pgsql con schema' => [
                ['driver' => 'pgsql', 'host' => 'h', 'database' => 'app', 'schema' => 'auth'],
                "pgsql:host=h;port=5432;dbname=app;options='--search_path=auth'",
            ],
            'mysql' => [
                ['driver' => 'mysql', 'host' => 'h', 'port' => 3306, 'database' => 'app'],
                'mysql:host=h;port=3306;dbname=app;charset=utf8mb4',
            ],
            'sqlite' => [
                ['driver' => 'sqlite', 'database' => '/tmp/app.sqlite'],
                'sqlite:/tmp/app.sqlite',
            ],
        ];
    }

    #[DataProvider('dsnCases')]
    public function testDsnBuilding(array $config, string $expected): void
    {
        $this->assertSame($expected, DatabaseManager::dsn($config));
    }
}
