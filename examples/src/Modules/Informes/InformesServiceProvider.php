<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes;

use HexaLite\Container\Container;
use HexaLite\Database\DatabaseInterface;
use HexaLite\Database\DatabaseManager;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;
use HexaLite\Examples\Modules\Informes\Infrastructure\Http\Controllers\InformeController;
use HexaLite\Examples\Modules\Informes\Infrastructure\Persistence\InMemoryInformeRepository;
use HexaLite\Examples\Modules\Informes\Infrastructure\Persistence\JsonFileInformeRepository;
use HexaLite\Examples\Modules\Informes\Infrastructure\Persistence\PdoInformeRepository;
use HexaLite\Providers\ProviderInterface;
use InvalidArgumentException;

/**
 * EL CABLEADO. Este fichero es la frontera entre "qué hace el negocio" y "con
 * qué herramientas lo hace", y es el único sitio de todo el módulo que conoce a
 * la vez el puerto y sus adaptadores.
 *
 * Que sea tan corto no es casualidad: es la factura de haber puesto las
 * dependencias mirando hacia adentro. Cambiar de fichero a PostgreSQL es cambiar
 * `INFORMES_DRIVER` en el entorno. Ni el dominio, ni los casos de uso, ni el
 * controlador se enteran.
 *
 * Todo se registra con `setFactory`, así que nada se instancia hasta que alguien
 * lo pide: una petición que no toca informes no abre la conexión a la base de datos.
 */
final class InformesServiceProvider implements ProviderInterface
{
    public const DRIVER_FICHERO = 'fichero';
    public const DRIVER_MEMORIA = 'memoria';
    public const DRIVER_PDO     = 'pdo';

    public function __construct(
        private readonly Container $container,
        private readonly string $driver = self::DRIVER_FICHERO,
        private readonly ?string $rutaFichero = null,
    ) {}

    /** Controladores que hay que pasarle al Router. */
    public static function controllers(): array
    {
        return [InformeController::class];
    }

    public function register(): void
    {
        if ($this->driver === self::DRIVER_PDO) {
            $this->registrarBaseDeDatos();
        }

        // ── La línea que lo justifica todo ────────────────────────────────────
        // Un solo punto de decisión sobre qué implementación entra por el puerto.
        // Los tres adaptadores cumplen `InformeRepositoryInterface`, así que para
        // los casos de uso son intercambiables.
        $this->container->setFactory(
            InformeRepositoryInterface::class,
            fn (Container $c): InformeRepositoryInterface => match ($this->driver) {
                self::DRIVER_MEMORIA => new InMemoryInformeRepository(),
                self::DRIVER_FICHERO => new JsonFileInformeRepository($this->rutaFichero ?? self::rutaPorDefecto()),
                self::DRIVER_PDO     => new PdoInformeRepository($c->get(DatabaseInterface::class)),
                default              => throw new InvalidArgumentException(
                    "Driver de informes desconocido: '{$this->driver}'. Usa 'fichero', 'memoria' o 'pdo'."
                ),
            },
        );
    }

    public function boot(): void
    {
        // Aviso temprano: con el driver de fichero, dos peticiones simultáneas se
        // pisan; y con el de memoria no se persiste nada entre peticiones porque
        // PHP-FPM es share-nothing. Ninguno de los dos es para producción.
        if ($this->driver !== self::DRIVER_PDO && env('APP_ENV', 'local') === 'production') {
            error_log(
                "[informes] El driver '{$this->driver}' no es para producción: "
                . "define INFORMES_DRIVER=pdo y la conexión de base de datos."
            );
        }
    }

    private function registrarBaseDeDatos(): void
    {
        // `fromEnv()` descubre la conexión leyendo DB_*, MYSQL_* o PGSQL_*.
        $this->container->setFactory(
            DatabaseManager::class,
            static fn (): DatabaseManager => DatabaseManager::fromEnv(),
        );

        $this->container->setFactory(
            DatabaseInterface::class,
            static fn (Container $c): DatabaseInterface => $c->get(DatabaseManager::class)->connection(),
        );
    }

    private static function rutaPorDefecto(): string
    {
        return dirname(__DIR__, 3) . '/var/informes.json';
    }
}
