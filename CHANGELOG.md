# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

### Added

- **Kit de autenticación** (`HexaLite\Auth\`), listo para usar registrando
  `AuthServiceProvider`. Sin dependencias nuevas:
  - Alta, inicio de sesión con «recordarme», logout con revocación, refresco
    transparente del access token, perfil propio y health check.
  - Verificación de correo (blanda por defecto, dura con
    `AUTH_REQUIRE_VERIFIED_EMAIL`), reenvío, olvidé/restablecer contraseña y
    cambio de contraseña con verificación de la actual.
  - «Continuar con Google»: un endpoint que entra o da de alta, más vincular y
    desvincular. La firma RS256 del ID token se verifica contra el JWKS con
    `openssl`, sin librerías de JWT.
  - `TokenService`: JWT HS256 propio, con claves separadas para access y refresh
    y negativa a arrancar con claves vacías o iguales.
  - Revocación de sesiones por marca de agua (`tokens_valid_after`).
  - RBAC con atributos `#[Roles]` y `#[Permission]`, y `#[Throttle]` sobre caché.
  - Middlewares `AuthMiddleware`, `CsrfMiddleware` (double-submit) y
    `SecurityHeadersMiddleware`.
  - `PasswordPolicy` configurable por entorno, compartida por registro, reset y
    cambio, para que las tres reglas no puedan divergir.
  - Esquema SQL idempotente para **PostgreSQL** y **MySQL** en
    `src/Auth/migrations/`, con usuarios, roles, permisos y semillas.
- **`DatabaseManager`**: descubre las conexiones del entorno (`DB_*`, `MYSQL_*`,
  `PGSQL_*`/`POSTGRES_*`), soporta pgsql/mysql/sqlite y las abre de forma
  perezosa. Avisa con un mensaje claro cuando falta el driver PDO.
- **`HexaLite\Cache`**: `CacheInterface` con `RedisCache` (habla con `ext-redis` o
  `predis/predis`, y degrada a no-op si Redis no responde), `ArrayCache` y una
  factoría que elige según el entorno.
- **`HexaLite\Mail`**: `MailerInterface` con `SmtpMailer` (cliente SMTP nativo con
  STARTTLS/SMTPS y AUTH), `ResendMailer`, `LogMailer` para desarrollo y una
  factoría por entorno.
- **`CorsMiddleware`** en el core, con credenciales seguras por defecto
  (nunca refleja un Origin arbitrario ni usa `*` junto a cookies).
- `Router::handle()` devuelve la `Response` sin enviarla: permite tests
  funcionales de extremo a extremo y embeber HexaLite en otros runtimes.
  `dispatch()` ahora es un envoltorio que la envía.
- `Response::getCookies()` para inspeccionar las cookies encoladas.
- **`HexaLite\Security\EncryptionService`**: cifrado simétrico autenticado
  (XSalsa20-Poly1305 vía libsodium) para guardar secretos de terceros en reposo
  —App Passwords de SMTP, tokens de integraciones—. La clave se deriva de
  `APP_ENCRYPTION_KEY` (o `JWT_SECRET` como respaldo) con BLAKE2b; `decrypt()`
  devuelve `null` ante un dato manipulado en vez de datos alterados. Registrado
  de forma perezosa por `AuthServiceProvider`.
- `#[Permission]` admite **alternativas** separadas por `|`
  (`#[Permission('vehiculos:ver|cotizaciones:ver')]`): basta cumplir una. Los
  argumentos siguen exigiéndose todos, y el 403 informa del requisito completo
  que falta.
- **Rol superusuario** opcional (`AUTH_SUPER_ROLE`): quien lo tenga pasa el
  `PermissionGuard` sin revisar permisos puntuales. Vacío por defecto, así que
  no añade ninguna consulta a quien no lo use.
- Helpers `console_log()` y `reportError()`: rastro rápido al log de PHP sin
  romper el cuerpo JSON, y registro de excepciones con el detalle de validación
  ya extraído.
- [`docs/CAPACIDADES.md`](docs/CAPACIDADES.md): catálogo completo de lo que hace
  el framework —ciclo de una petición, enrutado, contenedor, DTOs y todas las
  reglas de validación, middlewares, guards, auth y RBAC, base de datos, caché,
  correo, seguridad, códigos de error, rendimiento y pruebas— incluyendo los
  límites de cada capacidad y una sección de lo que deliberadamente NO hace.
- `src/Auth/migrations/seed_user_pgsql.sql`: siembra un administrador verificado
  y con rol, listo para iniciar sesión, para probar las rutas protegidas sin
  pasar por el registro ni por el correo. Idempotente, con recetas para cambiar
  la contraseña, promover a un usuario existente y añadir permisos.
- Ejemplo `AdminController`: rutas protegidas por sesión, rol, permiso,
  alternativas con `|` y throttle, enchufado al ejemplo ejecutable de auth.
- `.env.example` documentado y [`docs/FRONTEND.md`](docs/FRONTEND.md) con el
  contrato completo para el cliente.

### Fixed

- El Router **ignoraba la `Response` devuelta por un Guard**: al ser un objeto
  truthy, un rechazo con respuesta propia (401/403) dejaba pasar la petición al
  controlador. Ahora se respeta la respuesta del Guard.
- El Router registraba en el log **todas** las `HttpException`, incluidas las de
  control de flujo normal (401 de credenciales, 422 de enlace ya usado). Ahora
  solo se registran las 5xx, que son las que indican un fallo real.

### Removed

- `HexaLite\Http\Domain\ThrottleStoreInterface`: código muerto desde 1.0.0. El
  `ThrottleGuard` cuenta sobre `CacheInterface` (Redis compartido o memoria), así
  que nadie la implementaba ni la consumía. Si tenías un store propio, pásalo a
  `CacheInterface`.

## [1.0.0] - 2026-07-12

Primera versión pública de HexaLite como paquete independiente, extraída y
desacoplada de la aplicación donde nació.

### Added
- Núcleo del framework bajo el namespace `HexaLite\`:
  - **Router** por atributos (`#[Route]`, `#[Controller]`, `#[Middleware]`) con
    rutas estáticas O(1), mega-regex para rutas dinámicas y caché en producción.
  - **Container** de inyección de dependencias con autowiring y detección de
    dependencias circulares.
  - **DTOs autovalidados** (`HexaLite\Http\DTO\Dtos`) con 20 atributos de
    validación declarativa y un `RuleEngine` compartido.
  - **Request / Response / Body / Params** para HTTP síncrono (PHP-FPM).
  - **Validación** nativa estilo Laravel vía `$request->validate()`.
  - **Guards** y **Middlewares** por atributos, con soporte de throttling.
  - **Logging** PSR-3 (`LoggerFactory` + `SimpleLogger`, Monolog opcional).
  - **PDODatabase** para MySQL/PostgreSQL con prepared statements reales.
- `Router::registerExceptionHandler()` para mapear excepciones de terceros
  (p. ej. de una librería JWT) a respuestas HTTP sin acoplar el core.
- Helpers globales agnósticos (`response()`, `env()`, `loadEnv()`, `url()`).
- Suite de pruebas PHPUnit, workflow de CI (GitHub Actions) y ejemplo ejecutable.

### Changed
- El `Container` ya **no depende de la extensión PECL `ext-ds`**: la detección de
  ciclos usa un array nativo. El framework se instala sin extensiones PECL.
- `LoggerFactory::create()` recibe el directorio de logs por parámetro en vez de
  depender de una constante global de la aplicación.

### Removed
- Acoplamientos a la aplicación de origen:
  - `Request` ya no importa los servicios de token/JWT de la app. La
    autenticación se resuelve en un middleware que publica `$request->user()`.
  - El `Router` ya no referencia `Firebase\JWT\*`; usa el hook de excepciones.
  - Se eliminaron helpers de negocio (formato de moneda/fecha localizados, etc.)
    y las constantes de rutas específicas de la app (`PATH_VAR`, …).

[Unreleased]: https://github.com/jorge-koki/hexalite/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/jorge-koki/hexalite/releases/tag/v1.0.0
