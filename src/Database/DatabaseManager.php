<?php

declare(strict_types=1);

namespace HexaLite\Database;

use InvalidArgumentException;
use RuntimeException;

/**
 * Registro perezoso de conexiones a base de datos.
 *
 * Su razón de ser es el AUTO-DESCUBRIMIENTO: {@see self::fromEnv()} mira el
 * entorno y activa SOLO las conexiones cuyas variables estén llenas. Si defines
 * `DB_HOST`/`DB_NAME` tienes una conexión llamada `default`; si además llenas
 * `MYSQL_*` tienes otra llamada `mysql`, y así. Sin variables no se registra
 * nada y el framework arranca igual (útil para APIs sin BD).
 *
 * Las conexiones se abren la PRIMERA vez que se piden, nunca al arrancar: una
 * petición que no toca la BD no paga el coste del handshake.
 */
final class DatabaseManager
{
    /** Puertos por defecto de cada driver soportado. */
    private const DEFAULT_PORTS = ['pgsql' => 5432, 'mysql' => 3306];

    /** @var array<string, array<string, mixed>> Configuración por nombre de conexión. */
    private array $configs;

    /** @var array<string, DatabaseInterface> Conexiones ya abiertas (memoizadas). */
    private array $connections = [];

    private ?string $default;

    /**
     * @param array<string, array<string, mixed>> $configs Config por nombre.
     * @param string|null                         $default Conexión usada cuando se pide sin nombre.
     */
    public function __construct(array $configs = [], ?string $default = null)
    {
        $this->configs = $configs;
        $this->default = $default ?? array_key_first($configs);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTO-DESCUBRIMIENTO POR ENTORNO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el manager leyendo el entorno. Se registra una conexión por cada
     * grupo de variables COMPLETO (host + base de datos); los grupos vacíos se
     * ignoran en silencio.
     *
     *   DB_*      → conexión `default`. El driver sale de DB_CONNECTION/DB_DRIVER;
     *               si no se indica, se deduce del puerto (5432 → pgsql,
     *               3306 → mysql) y en última instancia es pgsql.
     *   PGSQL_*   → conexión `pgsql`  (alias aceptado: POSTGRES_*).
     *   MYSQL_*   → conexión `mysql`.
     *
     * Variables de cada grupo: `<P>_HOST`, `<P>_PORT`, `<P>_NAME` (o
     * `<P>_DATABASE`), `<P>_USER` (o `<P>_USERNAME`), `<P>_PASSWORD`, `<P>_CHARSET`,
     * `<P>_SCHEMA` (solo pgsql: fija el `search_path`).
     *
     * `DB_CONNECTION=sqlite` con `DB_NAME=/ruta/app.sqlite` también funciona.
     */
    public static function fromEnv(): self
    {
        $configs = [];

        $main = self::readGroup('DB');
        if ($main !== null) {
            $configs['default'] = $main;
        }

        foreach (['pgsql' => ['PGSQL', 'POSTGRES'], 'mysql' => ['MYSQL']] as $name => $prefixes) {
            foreach ($prefixes as $prefix) {
                $cfg = self::readGroup($prefix, $name);
                if ($cfg !== null) {
                    $configs[$name] = $cfg;
                    break;
                }
            }
        }

        // La conexión por defecto es `default` si existe; si no, la primera que
        // se haya descubierto (así una app que solo llena MYSQL_* funciona sin
        // tener que nombrar la conexión en cada inyección).
        return new self($configs, isset($configs['default']) ? 'default' : null);
    }

    /**
     * Lee un grupo de variables de entorno. Devuelve null si el grupo no está
     * "lleno" — es decir, si falta el host o el nombre de la base de datos —,
     * que es la señal de "esta conexión no está configurada, no la actives".
     *
     * @param string|null $forceDriver Driver fijo del grupo (los grupos PGSQL_ y MYSQL_ lo tienen implícito).
     * @return array<string, mixed>|null
     */
    private static function readGroup(string $prefix, ?string $forceDriver = null): ?array
    {
        $get = static function (string ...$keys): ?string {
            foreach ($keys as $key) {
                $value = getenv($key);
                if ($value === false) {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
                }
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            return null;
        };

        $driver = $forceDriver
            ?? $get("{$prefix}_CONNECTION", "{$prefix}_DRIVER");

        $database = $get("{$prefix}_NAME", "{$prefix}_DATABASE");
        $host     = $get("{$prefix}_HOST");
        $port     = $get("{$prefix}_PORT");

        // SQLite se identifica solo con el driver y la ruta del archivo.
        if ($driver === 'sqlite') {
            return $database === null ? null : ['driver' => 'sqlite', 'database' => $database];
        }

        // Grupo incompleto → conexión NO configurada.
        if ($host === null || $database === null) {
            return null;
        }

        if ($driver === null) {
            $driver = match ($port) {
                '3306'  => 'mysql',
                '5432'  => 'pgsql',
                default => 'pgsql',
            };
        }

        return array_filter([
            'driver'   => $driver,
            'host'     => $host,
            'port'     => $port !== null ? (int) $port : (self::DEFAULT_PORTS[$driver] ?? null),
            'database' => $database,
            'username' => $get("{$prefix}_USER", "{$prefix}_USERNAME") ?? '',
            'password' => $get("{$prefix}_PASSWORD", "{$prefix}_PASS") ?? '',
            'charset'  => $get("{$prefix}_CHARSET"),
            'schema'   => $get("{$prefix}_SCHEMA"),
        ], static fn($v) => $v !== null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API
    // ─────────────────────────────────────────────────────────────────────────

    /** ¿Hay alguna conexión configurada? */
    public function isEmpty(): bool
    {
        return $this->configs === [];
    }

    public function has(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    /** @return string[] Nombres de las conexiones configuradas. */
    public function names(): array
    {
        return array_keys($this->configs);
    }

    public function defaultName(): ?string
    {
        return $this->default;
    }

    /** @return array<string, mixed>|null */
    public function config(string $name): ?array
    {
        return $this->configs[$name] ?? null;
    }

    /** Registra (o reemplaza) una conexión en caliente. */
    public function addConnection(string $name, array $config, bool $asDefault = false): void
    {
        $this->configs[$name] = $config;
        unset($this->connections[$name]);
        if ($asDefault || $this->default === null) {
            $this->default = $name;
        }
    }

    /**
     * Abre (o reutiliza) una conexión. Sin nombre devuelve la conexión por defecto.
     *
     * @throws RuntimeException si el nombre no está configurado.
     */
    public function connection(?string $name = null): DatabaseInterface
    {
        $name ??= $this->default;

        if ($name === null) {
            throw new RuntimeException(
                'No hay ninguna conexión de base de datos configurada. '
                . 'Define DB_HOST y DB_NAME (o MYSQL_*/PGSQL_*) en tu .env.'
            );
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->configs[$name] ?? null;
        if ($config === null) {
            throw new RuntimeException(
                "La conexión '$name' no está configurada. Conexiones disponibles: "
                . (implode(', ', $this->names()) ?: '(ninguna)')
            );
        }

        return $this->connections[$name] = self::make($config);
    }

    /**
     * Crea una conexión suelta a partir de un array de configuración, verificando
     * antes que el driver PDO correspondiente esté instalado (el error nativo de
     * PDO por driver ausente es críptico: "could not find driver").
     */
    public static function make(array $config): DatabaseInterface
    {
        $driver = (string) ($config['driver'] ?? 'pgsql');

        if (!extension_loaded('pdo')) {
            throw new RuntimeException('La extensión ext-pdo es necesaria para conectarse a la base de datos.');
        }
        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                "El driver PDO '$driver' no está instalado. Instala la extensión pdo_$driver "
                . '(Debian/Ubuntu: php-' . ($driver === 'pgsql' ? 'pgsql' : $driver) . ').'
            );
        }

        return new PDODatabase(
            self::dsn($config),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            (array) ($config['options'] ?? []),
        );
    }

    /** Arma el DSN de PDO para el driver indicado. */
    public static function dsn(array $config): string
    {
        $driver   = (string) ($config['driver'] ?? 'pgsql');
        $host     = (string) ($config['host'] ?? '127.0.0.1');
        $database = (string) ($config['database'] ?? '');
        $port     = (int) ($config['port'] ?? self::DEFAULT_PORTS[$driver] ?? 0);

        return match ($driver) {
            'pgsql' => rtrim(sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port ?: 5432,
                $database
            ) . (isset($config['schema']) ? ";options='--search_path={$config['schema']}'" : ''), ';'),

            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port ?: 3306,
                $database,
                (string) ($config['charset'] ?? 'utf8mb4')
            ),

            'sqlite' => 'sqlite:' . $database,

            default => throw new InvalidArgumentException("Driver de base de datos no soportado: '$driver'."),
        };
    }
}
