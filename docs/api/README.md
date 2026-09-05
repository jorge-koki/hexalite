# Referencia de API

Referencia clase por clase de HexaLite. Documenta **la superficie pública**:
constructores, métodos públicos, parámetros, valores de retorno y excepciones.

Para aprender el framework, empieza por otro lado — esto es material de consulta:

- [`../../README.md`](../../README.md) — introducción y ejemplos de arranque.
- [`../CAPACIDADES.md`](../CAPACIDADES.md) — qué hace cada pieza y dónde está su límite.
- [`../FRONTEND.md`](../FRONTEND.md) — contrato con el cliente (cookies, CSRF, endpoints).

Todas las clases viven bajo el namespace `HexaLite\`, mapeado por PSR-4 a `src/`.

---

## Por módulo

| Documento | Contiene |
|---|---|
| [`http.md`](http.md) | `Request`, `Response`, `Router`, `Body`, `Params`, excepciones HTTP, interfaces de middleware y guard, CORS, atributos de ruta y helpers globales |
| [`dto-validacion.md`](dto-validacion.md) | `Dtos`, los 18 atributos de validación, `RuleEngine`, `Validator` y el contrato `CustomValidator` |
| [`contenedor.md`](contenedor.md) | `Container`, sus excepciones, el atributo `#[Inject]`, `ProviderInterface` y `PhpExporter` |
| [`base-de-datos.md`](base-de-datos.md) | `DatabaseManager`, `DatabaseInterface`, `PDODatabase`, `QueryResult` |
| [`cache.md`](cache.md) | `CacheInterface`, `CacheFactory`, `RedisCache`, `ArrayCache` |
| [`correo.md`](correo.md) | `MailerInterface`, `MailerFactory`, `SmtpMailer`, `ResendMailer`, `LogMailer` |
| [`logging-seguridad.md`](logging-seguridad.md) | `LoggerFactory`, `SimpleLogger`, `EncryptionService` |
| [`auth.md`](auth.md) | El kit de autenticación completo: configuración, dominio, casos de uso, servicios, guards, middlewares, atributos y endpoints |

---

## Índice alfabético de clases

| Clase | Tipo | Documento |
|---|---|---|
| `Attributes\Controller` | atributo | [http](http.md#controller) |
| `Attributes\Inject` | atributo | [contenedor](contenedor.md#inject) |
| `Attributes\Middleware` | atributo | [http](http.md#middleware) |
| `Attributes\Route` | atributo | [http](http.md#route) |
| `Attributes\TimeAlwaysExecuted` | atributo | [http](http.md#timealwaysexecuted) |
| `Auth\Application\AuthSession` | clase | [auth](auth.md#authsession) |
| `Auth\Application\Dtos\*` | DTOs (8) | [auth](auth.md#dtos-de-entrada) |
| `Auth\Application\UseCases\*` | casos de uso (10) | [auth](auth.md#casos-de-uso) |
| `Auth\Attributes\Permission` | atributo | [auth](auth.md#permission) |
| `Auth\Attributes\Roles` | atributo | [auth](auth.md#roles) |
| `Auth\Attributes\Throttle` | atributo | [auth](auth.md#throttle) |
| `Auth\AuthConfig` | clase | [auth](auth.md#authconfig) |
| `Auth\AuthServiceProvider` | clase | [auth](auth.md#authserviceprovider) |
| `Auth\Domain\User` | clase | [auth](auth.md#user) |
| `Auth\Domain\UserRepositoryInterface` | interfaz | [auth](auth.md#userrepositoryinterface) |
| `Auth\Exceptions\ExpiredTokenException` | excepción | [auth](auth.md#excepciones-de-token) |
| `Auth\Exceptions\InvalidTokenException` | excepción | [auth](auth.md#excepciones-de-token) |
| `Auth\Exceptions\TokenException` | excepción | [auth](auth.md#excepciones-de-token) |
| `Auth\Guards\PermissionGuard` | guard | [auth](auth.md#permissionguard) |
| `Auth\Guards\RoleGuard` | guard | [auth](auth.md#roleguard) |
| `Auth\Guards\ThrottleGuard` | guard | [auth](auth.md#throttleguard) |
| `Auth\Infrastructure\Controllers\AuthController` | controlador | [auth](auth.md#authcontroller) |
| `Auth\Infrastructure\Persistence\PdoUserRepository` | repositorio | [auth](auth.md#pdouserrepository) |
| `Auth\Middlewares\AuthMiddleware` | middleware | [auth](auth.md#authmiddleware) |
| `Auth\Middlewares\CsrfMiddleware` | middleware | [auth](auth.md#csrfmiddleware) |
| `Auth\Middlewares\SecurityHeadersMiddleware` | middleware | [auth](auth.md#securityheadersmiddleware) |
| `Auth\Services\EmailTemplates` | servicio | [auth](auth.md#emailtemplates) |
| `Auth\Services\GoogleIdTokenService` | servicio | [auth](auth.md#googleidtokenservice) |
| `Auth\Services\JwtPayload` | clase | [auth](auth.md#jwtpayload) |
| `Auth\Services\PasswordPolicy` | servicio | [auth](auth.md#passwordpolicy) |
| `Auth\Services\RecaptchaService` | servicio | [auth](auth.md#recaptchaservice) |
| `Auth\Services\SessionCookies` | servicio | [auth](auth.md#sessioncookies) |
| `Auth\Services\TokenRevocationService` | servicio | [auth](auth.md#tokenrevocationservice) |
| `Auth\Services\TokenService` | servicio | [auth](auth.md#tokenservice) |
| `Cache\ArrayCache` | clase | [cache](cache.md#arraycache) |
| `Cache\CacheFactory` | clase | [cache](cache.md#cachefactory) |
| `Cache\CacheInterface` | interfaz | [cache](cache.md#cacheinterface) |
| `Cache\RedisCache` | clase | [cache](cache.md#rediscache) |
| `Container\CircularDependencyException` | excepción | [contenedor](contenedor.md#excepciones) |
| `Container\Container` | clase | [contenedor](contenedor.md#container) |
| `Container\ContainerException` | excepción | [contenedor](contenedor.md#excepciones) |
| `Container\NotFoundException` | excepción | [contenedor](contenedor.md#excepciones) |
| `Database\DatabaseInterface` | interfaz | [base-de-datos](base-de-datos.md#databaseinterface) |
| `Database\DatabaseManager` | clase | [base-de-datos](base-de-datos.md#databasemanager) |
| `Database\PDODatabase` | clase | [base-de-datos](base-de-datos.md#pdodatabase) |
| `Database\QueryResult` | clase | [base-de-datos](base-de-datos.md#queryresult) |
| `Http\Body` | clase | [http](http.md#body) |
| `Http\DTO\Attributes\*` | atributos (18) | [dto-validacion](dto-validacion.md#atributos-de-validación) |
| `Http\DTO\Dtos` | clase abstracta | [dto-validacion](dto-validacion.md#dtos) |
| `Http\DTO\Validators\CustomValidator` | interfaz | [dto-validacion](dto-validacion.md#customvalidator) |
| `Http\Domain\GuardInterface` | interfaz | [http](http.md#guardinterface) |
| `Http\Domain\MiddlewareInterface` | interfaz | [http](http.md#middlewareinterface) |
| `Http\HttpException` | excepción | [http](http.md#httpexception) |
| `Http\Middlewares\CorsMiddleware` | middleware | [http](http.md#corsmiddleware) |
| `Http\Params` | clase | [http](http.md#params) |
| `Http\Request` | clase | [http](http.md#request) |
| `Http\Response` | clase | [http](http.md#response) |
| `Http\Router` | clase | [http](http.md#router) |
| `Http\ValidationException` | excepción | [http](http.md#validationexception) |
| `Logging\LoggerFactory` | clase | [logging-seguridad](logging-seguridad.md#loggerfactory) |
| `Logging\SimpleLogger` | clase | [logging-seguridad](logging-seguridad.md#simplelogger) |
| `Mail\LogMailer` | clase | [correo](correo.md#logmailer) |
| `Mail\MailerFactory` | clase | [correo](correo.md#mailerfactory) |
| `Mail\MailerInterface` | interfaz | [correo](correo.md#mailerinterface) |
| `Mail\ResendMailer` | clase | [correo](correo.md#resendmailer) |
| `Mail\SmtpMailer` | clase | [correo](correo.md#smtpmailer) |
| `Providers\ProviderInterface` | interfaz | [contenedor](contenedor.md#providerinterface) |
| `ResponseFactory` | clase | [http](http.md#responsefactory) |
| `Security\EncryptionService` | clase | [logging-seguridad](logging-seguridad.md#encryptionservice) |
| `Support\PhpExporter` | clase *(interna)* | [contenedor](contenedor.md#phpexporter) |
| `Validation\RuleEngine` | clase | [dto-validacion](dto-validacion.md#ruleengine) |
| `Validation\Validator` | clase | [dto-validacion](dto-validacion.md#validator) |

---

## Convenciones de esta referencia

- Las firmas se muestran tal cual las declara el código, con los tipos nativos de PHP 8.2+.
- «Lanza» lista solo las excepciones que la clase lanza **a propósito**; los `TypeError`
  por tipos incorrectos no se documentan.
- Los métodos `static ...FromEnv()` siguen todos la misma regla del framework:
  **leen el entorno y se activan solos si sus variables están llenas**; si no lo están,
  devuelven una variante degradada que no rompe (memoria en vez de Redis, log en vez de
  correo) en lugar de fallar al arrancar.
