<?php

declare(strict_types=1);

namespace HexaLite\Auth;

use HexaLite\Auth\Attributes\Permission;
use HexaLite\Auth\Attributes\Roles;
use HexaLite\Auth\Attributes\Throttle;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Guards\PermissionGuard;
use HexaLite\Auth\Guards\ThrottleGuard;
use HexaLite\Auth\Infrastructure\Controllers\AuthController;
use HexaLite\Auth\Infrastructure\Persistence\PdoUserRepository;
use HexaLite\Auth\Services\EmailTemplates;
use HexaLite\Auth\Services\GoogleIdTokenService;
use HexaLite\Auth\Services\PasswordPolicy;
use HexaLite\Auth\Services\RecaptchaService;
use HexaLite\Auth\Services\SessionCookies;
use HexaLite\Auth\Services\TokenRevocationService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Cache\CacheFactory;
use HexaLite\Cache\CacheInterface;
use HexaLite\Container\Container;
use HexaLite\Database\DatabaseInterface;
use HexaLite\Database\DatabaseManager;
use HexaLite\Mail\MailerFactory;
use HexaLite\Mail\MailerInterface;
use HexaLite\Providers\ProviderInterface;
use HexaLite\Security\EncryptionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registra el kit de autenticación completo con CERO configuración: se lee todo
 * del entorno y cada pieza se activa sola si sus variables están llenas
 * (PostgreSQL/MySQL, Redis, SMTP/Resend, Google, reCAPTCHA).
 *
 * Uso en el front controller:
 *
 *   $container = new Container($cache, $isProduction);
 *   (new AuthServiceProvider($container))->register();
 *
 *   $router = new Router(
 *       controllers:     [...AuthServiceProvider::controllers(), MiControlador::class],
 *       container:       $container,
 *       guardAttributes: AuthServiceProvider::guardAttributes(),
 *   );
 *
 * Todos los enlaces se registran con `setFactory`, así que nada se instancia
 * hasta que alguien lo pide: una petición que no toca la autenticación no abre
 * la conexión a la base de datos ni la de Redis.
 *
 * Cualquier enlace se puede sobrescribir DESPUÉS registrando el tuyo: el
 * container se queda con el último. Lo habitual es reemplazar
 * {@see UserRepositoryInterface} para apuntar a tu propia tabla de usuarios.
 */
final class AuthServiceProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $config Sobrescrituras opcionales:
     *        'connection'   => nombre de la conexión de BD a usar,
     *        'table_prefix' => prefijo de las tablas de auth,
     *        'auth'         => una instancia de AuthConfig ya construida.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config = [],
    ) {
    }

    /** Controladores que hay que pasarle al Router. */
    public static function controllers(): array
    {
        return [AuthController::class];
    }

    /** Atributos-guard que hay que pasarle al Router para que los reconozca. */
    public static function guardAttributes(): array
    {
        return [Throttle::class, Roles::class, Permission::class];
    }

    public function register(): void
    {
        $this->registerInfrastructure();
        $this->registerAuth();
    }

    public function boot(): void
    {
        // Aviso temprano y ruidoso: en producción, un mailer sin configurar
        // significa que NADIE recibe el correo de verificación ni el de
        // restablecimiento — y eso se descubre tarde y mal si no se avisa aquí.
        $config = $this->container->get(AuthConfig::class);

        if ($config->isProduction && !$this->container->get(MailerInterface::class)->isConfigured()) {
            $warning = '[auth] No hay proveedor de correo configurado: los correos de verificación y '
                . 'restablecimiento NO se están entregando. Define MAIL_HOST o RESEND_API_KEY.';

            $logger = $this->logger();
            // Sin logger, al log de PHP: este aviso no puede perderse en silencio.
            $logger !== null ? $logger->warning($warning) : error_log($warning);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INFRAESTRUCTURA (base de datos, caché, correo)
    // ─────────────────────────────────────────────────────────────────────────

    private function registerInfrastructure(): void
    {
        // ── Bases de datos ───────────────────────────────────────────────────
        // Se registra una fábrica por conexión descubierta (`database.default`,
        // `database.mysql`…) además del alias DatabaseInterface para la principal.
        $this->container->setFactory(DatabaseManager::class, static fn() => DatabaseManager::fromEnv());

        $manager = DatabaseManager::fromEnv();

        foreach ($manager->names() as $name) {
            $this->container->setFactory(
                "database.$name",
                static fn(Container $c) => $c->get(DatabaseManager::class)->connection($name),
            );
        }

        // Se registra SIEMPRE, incluso sin conexiones descubiertas: así, cuando
        // falta la configuración, el error es el mensaje explícito de
        // DatabaseManager («define DB_HOST y DB_NAME…») y no un críptico "clase
        // no encontrada en el contenedor".
        $connection = $this->config['connection'] ?? null;
        $this->container->setFactory(
            DatabaseInterface::class,
            static fn(Container $c) => $c->get(DatabaseManager::class)->connection($connection),
        );

        // ── Caché (Redis si está configurado, memoria si no) ─────────────────
        $this->container->setFactory(CacheInterface::class, static fn() => CacheFactory::fromEnv());

        // ── Cifrado de secretos en reposo ────────────────────────────────────
        // Perezoso a propósito: sin APP_ENCRYPTION_KEY el constructor lanza, y no
        // queremos que eso tumbe a las apps que nunca cifran nada.
        $this->container->setFactory(EncryptionService::class, static fn() => new EncryptionService());

        // ── Correo ───────────────────────────────────────────────────────────
        $this->container->setFactory(
            MailerInterface::class,
            fn() => MailerFactory::fromEnv($this->logger()),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTENTICACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    private function registerAuth(): void
    {
        $authConfig = ($this->config['auth'] ?? null) instanceof AuthConfig
            ? $this->config['auth']
            : AuthConfig::fromEnv();

        $this->container->set(AuthConfig::class, $authConfig);

        $this->container->setFactory(
            PasswordPolicy::class,
            static fn() => PasswordPolicy::fromEnv(),
        );

        $this->container->setFactory(
            EmailTemplates::class,
            static fn(Container $c) => new EmailTemplates($c->get(AuthConfig::class)->appName),
        );

        $this->container->setFactory(
            TokenService::class,
            static fn() => new TokenService(
                accessTtl:  (int) (getenv('JWT_ACCESS_TTL') ?: 900),
                refreshTtl: (int) (getenv('JWT_REFRESH_TTL') ?: 604800),
                issuer:     getenv('APP_NAME') ?: 'hexalite',
            ),
        );

        $this->container->setFactory(
            SessionCookies::class,
            static fn(Container $c) => new SessionCookies($c->get(AuthConfig::class)),
        );

        // El repositorio necesita saber el driver para elegir el prefijo de tabla
        // ('auth.' en PostgreSQL, 'auth_' en MySQL) y si puede usar RETURNING.
        $this->container->setFactory(
            UserRepositoryInterface::class,
            function (Container $c) {
                $manager    = $c->get(DatabaseManager::class);
                $connection = $this->config['connection'] ?? $manager->defaultName();
                $driver     = (string) ($manager->config((string) $connection)['driver'] ?? 'pgsql');

                return new PdoUserRepository(
                    $c->get(DatabaseInterface::class),
                    $driver,
                    $this->config['table_prefix'] ?? null,
                );
            },
        );

        // El caché es OPCIONAL aquí: si Redis no responde, la revocación lee de la
        // BD en cada petición — correcto, solo un poco más lento.
        $this->container->setFactory(
            TokenRevocationService::class,
            static fn(Container $c) => new TokenRevocationService(
                $c->get(UserRepositoryInterface::class),
                $c->get(CacheInterface::class),
            ),
        );

        // El guard se registra a mano solo para inyectarle el rol superusuario;
        // sin AUTH_SUPER_ROLE el valor es '' y el atajo queda desactivado.
        $this->container->setFactory(
            PermissionGuard::class,
            static fn(Container $c) => new PermissionGuard(
                $c->get(UserRepositoryInterface::class),
                $c->get(AuthConfig::class)->superRole,
            ),
        );

        $this->container->setFactory(
            ThrottleGuard::class,
            static fn(Container $c) => new ThrottleGuard($c->get(CacheInterface::class)),
        );

        $this->container->setFactory(
            GoogleIdTokenService::class,
            static fn() => new GoogleIdTokenService(
                (string) (getenv('GOOGLE_CLIENT_ID') ?: ''),
                getenv('CACHE_DIR') ?: null,
            ),
        );

        // Sin RECAPTCHA_SECRET el servicio existe pero no verifica nada, así que
        // los casos de uso pueden depender de él sin condicionales.
        $this->container->setFactory(
            RecaptchaService::class,
            static fn() => new RecaptchaService(
                (string) (getenv('RECAPTCHA_SECRET') ?: ''),
                (float) (getenv('RECAPTCHA_MIN_SCORE') ?: 0.5),
            ),
        );

        // El resto —casos de uso, guards, middlewares y el controlador— lo resuelve
        // el autowiring del contenedor a partir de estos enlaces.
    }

    private function logger(): ?LoggerInterface
    {
        try {
            return $this->container->has(LoggerInterface::class)
                ? $this->container->get(LoggerInterface::class)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
