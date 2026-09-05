# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

## [0.1.1] - 2026-09-04

Mejora de rendimiento en los archivos de caché que genera el framework. Sin
cambios incompatibles: actualizar desde 0.1.0 no exige tocar código.

### Changed

- Los cachés del **Router** (rutas compiladas) y del **Container** (metadata de
  constructores) se escriben ahora en **una sola línea** en vez de con el formato
  multilínea de `var_export()`. Medido sobre una tabla de 200 rutas: el archivo
  pasa de 265 KB y ~11.000 líneas a 115 KB y una línea (**56 % menos**), y se
  carga un **27 % más rápido sin OPcache** y un **21 % con OPcache caliente**.
  De esa mejora, unos 17 puntos vienen de quitar espacios y saltos, y el resto de
  omitir las claves redundantes de las listas.

  El archivo generado sigue siendo código PHP normal: `require` lo carga igual y
  OPcache lo compila igual. No es un formato propio.

### Added

- `HexaLite\Support\PhpExporter`, el exportador que sustituye a `var_export()`
  para esos cachés. Está marcado `@internal`: es un detalle de implementación y
  no forma parte de la API estable. Trata con cuidado los casos que rompen un
  archivo que va a ejecutarse — `PHP_INT_MIN` se emite como resta (como literal
  el parser lo convertiría en `float`), los caracteres de control van escapados
  como `\xNN`, y un valor no exportable lanza, de modo que ante un fallo el
  resultado es quedarse sin caché en vez de escribir uno que reventaría al
  hacerle `require`.

### Al actualizar

**Borra los cachés existentes para que se regeneren.** En producción, el Router
carga el archivo de caché si existe y nunca lo reescribe, así que un `routes.php`
generado con 0.1.0 seguirá en el formato antiguo indefinidamente. No se rompe
nada —el formato viejo es PHP válido y se carga sin problema—, simplemente no se
nota la mejora:

```sh
rm -f var/cache/routes.php var/cache/container.php
```

Ajusta la ruta a la que pases como `cacheFile` al construir el `Router` y el
`Container`. El caché del contenedor solo reaparece cuando este resuelve por
autowiring alguna clase que no estuviera ya cacheada.

## [0.1.0] - 2026-09-02

Primera versión publicada de HexaLite como paquete independiente, extraída y
desacoplada de la aplicación donde nació.

Sale como **0.1.0 y no como 1.0.0** a propósito: el framework es funcional y
tiene suite de pruebas, pero todavía no se ha ejercitado a fondo en producción.
Mientras la serie sea `0.x`, un cambio incompatible puede llegar en cualquier
versión menor. Fija la dependencia con `^0.1` —que Composer resuelve como
`>=0.1.0 <0.2.0`— para que no te suba de rama sin avisar.

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
- `Router::handle()` devuelve la `Response` sin enviarla: permite tests
  funcionales de extremo a extremo y embeber HexaLite en otros runtimes.
  `dispatch()` es un envoltorio que la envía.
- `Response::getCookies()` para inspeccionar las cookies encoladas.
- Helpers globales agnósticos (`response()`, `env()`, `loadEnv()`, `url()`).
- El `Container` **no depende de la extensión PECL `ext-ds`**: la detección de
  ciclos usa un array nativo. El framework se instala sin extensiones PECL.
- Suite de pruebas PHPUnit, workflow de CI (GitHub Actions) y ejemplos ejecutables.
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
- **Ejemplo de arquitectura hexagonal por módulos**
  (`examples/src/Modules/Informes/`), con su front controller
  `examples/public/hexagonal.php`. Arranca sin base de datos y recorre el flujo
  completo: puerto de salida en `Domain/Interfaces/`, tres adaptadores
  intercambiables (memoria, fichero JSON y PDO), casos de uso que dependen solo
  de la interfaz, reglas de negocio dentro de la entidad, excepciones de dominio
  traducidas a HTTP con `registerExceptionHandler()` y un provider que cablea
  todo en una sola línea. Incluye `tests/Examples/InformesHexagonalTest.php`,
  que prueba las reglas del negocio sin base de datos, sin servidor y sin mocks.

### Notas sobre el historial previo

Antes de esta versión, el CHANGELOG de este repositorio publicaba una sección
`[1.0.0] - 2026-07-12` y otra `[Unreleased]` que **nunca se llegaron a
etiquetar**: no existió ningún tag ni ninguna release, y el enlace de comparación
`v1.0.0...HEAD` apuntaba a un tag inexistente. Todo aquel contenido es, en la
práctica, esta `0.1.0`, y se consolidó aquí para que el historial no prometa
versiones que nunca se publicaron.

Por el mismo motivo esta versión no lleva secciones `Fixed` ni `Removed`: los
arreglos que figuraban como pendientes (la `Response` de un Guard que el Router
ignoraba, y el registro en el log de todas las `HttpException` en vez de solo las
5xx) y la retirada de `HexaLite\Http\Domain\ThrottleStoreInterface` ocurrieron
antes de que hubiera nada publicado. Forman parte de esta primera entrega y no
corrigen ninguna versión anterior, porque no la hay.

[Unreleased]: https://github.com/jorge-koki/hexalite/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/jorge-koki/hexalite/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/jorge-koki/hexalite/releases/tag/v0.1.0
