# Qué puede hacer HexaLite

Referencia completa de las capacidades del framework: qué trae, cómo se usa cada
pieza y —lo que suele faltar en un README— **dónde está el límite de cada una**.

El [README](../README.md) es la introducción; esto es el catálogo. Para el
contrato del cliente HTTP, ve a [FRONTEND.md](FRONTEND.md).

---

## Índice

1. [Qué es y qué no es](#1-qué-es-y-qué-no-es)
2. [El ciclo de una petición](#2-el-ciclo-de-una-petición)
3. [Enrutado](#3-enrutado)
4. [Contenedor de dependencias](#4-contenedor-de-dependencias)
5. [Request y Response](#5-request-y-response)
6. [DTOs autovalidados](#6-dtos-autovalidados)
7. [Validación sin DTO](#7-validación-sin-dto)
8. [Middlewares](#8-middlewares)
9. [Guards](#9-guards)
10. [Autenticación y RBAC](#10-autenticación-y-rbac)
11. [Base de datos](#11-base-de-datos)
12. [Caché](#12-caché)
13. [Correo](#13-correo)
14. [Logging](#14-logging)
15. [Seguridad](#15-seguridad)
16. [Errores y códigos HTTP](#16-errores-y-códigos-http)
17. [Rendimiento en producción](#17-rendimiento-en-producción)
18. [Pruebas](#18-pruebas)
19. [Variables de entorno](#19-variables-de-entorno)
20. [Lo que NO hace](#20-lo-que-no-hace)

---

## 1. Qué es y qué no es

HexaLite es un microframework **síncrono y share-nothing** para PHP-FPM: cada
petición arranca, resuelve y muere. No hay servidor de aplicación, ni bucle de
eventos, ni estado entre peticiones. Eso condiciona todo lo demás — y explica,
por ejemplo, por qué un contador de rate limit necesita Redis para existir.

Está pensado para **APIs JSON**. No trae motor de plantillas ni gestión de
assets; el front va aparte y habla por HTTP.

| | |
|---|---|
| **PHP mínimo** | 8.2 |
| **Dependencias obligatorias** | `psr/log` (y `ext-json`, `ext-mbstring`) |
| **Extensiones PECL** | ninguna obligatoria |
| **Base de datos** | PostgreSQL, MySQL/MariaDB, SQLite vía PDO |
| **Instalación** | `composer require hexalite/framework` |

---

## 2. El ciclo de una petición

Saber en qué orden pasa todo es lo que evita la mayoría de los errores de
configuración (un guard que nunca ve la sesión, un CORS que no llega al 401):

```
Request::createFromGlobals()
        │
        ▼
 Middlewares GLOBALES        ← por prioridad; envuelven TODO, incluidos los 404
        │                      y los errores. Aquí va CORS.
        ▼
 Ruta encontrada?  ──no──►  404 (pasando por los middlewares globales)
        │ sí
        ▼
 Middlewares DE RUTA         ← #[Middleware(...)] de la clase y del método.
        │                      Aquí va la autenticación.
        ▼
 GUARDS de la ruta           ← #[Throttle], #[Roles], #[Permission], por
        │                      prioridad. Devuelven true, false o su Response.
        ▼
 Hidratación de argumentos   ← {id} casteado, DTO validado (422 si falla),
        │                      servicios inyectados desde el contenedor
        ▼
     CONTROLADOR
        │
        ▼
     Response::send()
```

Dos consecuencias que conviene tener presentes:

- **Los middlewares corren antes que los guards**, y por eso funciona el
  RBAC: el `AuthMiddleware` valida la sesión y publica al usuario, y el
  `#[Permission]` ya lo encuentra ahí. Una ruta con `#[Permission]` **sin**
  middleware de auth responde 401 siempre, porque nadie publicó la sesión.
- **Las excepciones se convierten en `Response` DENTRO de la cadena**, así que un
  401 o un 422 salen con las cabeceras CORS puestas. Si se construyeran fuera, el
  navegador los convertiría en un opaco «Failed to fetch» y el front nunca vería
  el status real — que es exactamente lo que rompe el refresco de sesión.

El ejemplo [`AdminController`](../examples/src/Controllers/AdminController.php)
tiene el montaje completo funcionando.

---

## 3. Enrutado

Las rutas se declaran con atributos sobre los métodos del controlador. No hay
archivo de rutas que mantener en paralelo.

```php
#[Controller('/api/users')]                 // prefijo común
#[Middleware(AuthMiddleware::class)]        // aplica a todos los métodos
final class UserController
{
    #[Route('', 'GET')]                     // GET /api/users
    public function index(): Response {}

    #[Route('/{id}', 'GET')]                // GET /api/users/42 → $id = 42 (int)
    public function show(int $id): Response {}

    #[Route('', 'POST')]
    #[Middleware(CsrfMiddleware::class, priority: 50)]
    public function store(CreateUserDto $dto): Response {}
}
```

**Lo que resuelve el Router por ti:**

| Capacidad | Detalle |
|---|---|
| Rutas estáticas | Búsqueda O(1) en tabla hash |
| Rutas dinámicas | Un solo mega-regex compilado con `(*MARK)` (patrón FastRoute) |
| Parámetros | `{id}` se castea al tipo del parámetro del método (`int`, `string`…) |
| Inyección en la acción | DTOs (validados), servicios del contenedor y `Request` |
| Caché | En producción la tabla se serializa a un `.php` que OPcache mantiene en memoria |
| Prefijo de despliegue | `setBaseUrl('/api')` si la app cuelga de un subdirectorio |
| Middlewares globales | `addGlobalMiddleware($clase, priority: 1)` |
| Excepciones de terceros | `registerExceptionHandler(Clase::class, fn($e) => Response::json(...))` |
| Inspección | `printRoutes()` en desarrollo, `getRoutesInfo()` para tests o para publicar el mapa |
| Sin enviar | `handle($request)` devuelve la `Response`; `dispatch()` la envía |

**Orden de declaración:** las rutas estáticas deben ir **antes** que las
dinámicas del mismo prefijo. `/users/export` declarado después de `/users/{id}`
nunca se alcanza: `{id}` se lo come.

```php
$router->registerExceptionHandler(\Firebase\JWT\ExpiredException::class,
    fn() => Response::json(['error' => 'Unauthorized'], 401));
```

---

## 4. Contenedor de dependencias

Autowiring por reflexión de constructores, con la metadata cacheada.

```php
$container = new Container(cacheFile: __DIR__ . '/../var/cache/container.php', isProduction: true);

$container->set(Config::class, $config);                     // instancia ya creada
$container->setFactory(Mailer::class, fn($c) => new Mailer());// singleton perezoso
$container->transient(Uuid::class, fn() => Uuid::v4());      // una nueva cada vez
$container->bind(UserRepositoryInterface::class, PgUserRepository::class);
```

| Capacidad | Detalle |
|---|---|
| Autowiring | Resuelve dependencias del constructor por tipo, recursivamente |
| Singletons | Todo lo resuelto se guarda; `transient()` es la excepción explícita |
| Perezoso | Una factoría no se ejecuta hasta que alguien pide el servicio |
| Alias | `#[Inject('database.replica')]` o `#[Inject(MiEnum::REPLICA)]` en un parámetro |
| Ciclos | `A → B → A` lanza `CircularDependencyException`, no un fatal por pila agotada |
| Caché | En producción, constructores y bindings se escriben a disco de forma atómica |
| Último gana | Volver a registrar un id sustituye al anterior: así se sobrescribe el kit |

Los valores escalares con default (`private string $prefijo = 'x'`) se resuelven
solos; los escalares **sin** default hay que registrarlos con una factoría.

---

## 5. Request y Response

```php
$request->input('email', 'default');   // body ∪ query, con default
$request->all();                       // todo junto
$request->getMethod();  $request->getPath();  $request->getIp();
$request->getHeader('Authorization');  $request->getBearerToken();
$request->cookie('access_token');      $request->hasCookie('csrf_token');
$request->getFile('avatar');           $request->hasFiles();
$request->user();                      // lo que publicó el middleware de auth
$request->getAttribute('lo-que-sea');  $request->setAttribute('k', $v);
$request->validate([...]);             // 422 automático
```

`getIp()` entiende `X-Forwarded-For` **solo** desde proxies declarados con
`Request::setTrustedProxies(['10.0.0.0/8'])`. Sin eso, la IP es la de la
conexión: cualquiera podría falsear la cabecera y burlar el rate limit.

```php
Response::json(['data' => $x], 201)
    ->withHeaders(['X-Total' => '99'])
    ->withCookie('token', $jwt, ['httponly' => true, 'samesite' => 'Lax']);

response(['ok' => true]);          // helper equivalente
response()->withCookie(...);       // proxy fluido
```

Las cookies se **encolan** y se emiten en `send()`. La clave de la cola incluye
nombre + dominio + path, así que se pueden emitir dos cookies con el mismo
nombre y distinto dominio en una respuesta (borrar la vieja host-only y fijar la
de dominio) sin que una pise a la otra. `getCookies()` las inspecciona en tests.

**Helpers globales:** `response()`, `env()`, `loadEnv()`, `url()`,
`console_log()`, `reportError()`. Todos protegidos con `function_exists()`, así
que tu app puede redefinirlos.

---

## 6. DTOs autovalidados

Un DTO declara sus reglas como atributos. Si el controlador lo pide como
parámetro, el Router lo hidrata, lo valida y responde **422** solo, con el
detalle campo por campo. El cuerpo del controlador solo ve datos válidos.

```php
final class CreateUserDto extends Dtos
{
    #[IsRequired] #[IsString] #[Min(2)] #[Max(80)] #[NoHtml]
    public string $name;

    #[IsRequired] #[IsEmail]
    public string $email;

    #[IsRequired] #[Min(8)] #[Confirmed]        // exige password_confirmation
    public string $password;

    #[Nullable] #[In(['mx', 'do'])]
    public ?string $country = null;
}
```

| Atributo | Regla | Notas |
|---|---|---|
| `#[IsRequired]` | `required` | Rechaza null, `''` y arrays vacíos |
| `#[Nullable]` | `nullable` | Si el valor es null, **se saltan las demás reglas** |
| `#[IsString]` | `string` | Acepta numéricos convertibles |
| `#[IsInt]` | `int` | Vía `FILTER_VALIDATE_INT` |
| `#[IsFloat]` / `#[IsNumeric]` | `float` / `numeric` | |
| `#[IsBoolean]` | `boolean` | Acepta `true/false/1/0/'true'/'false'` |
| `#[IsEmail]` | `email` | |
| `#[IsUrl]` | `url` | |
| `#[IsArray]` | `array` | |
| `#[Min(n)]` / `#[Max(n)]` | `min` / `max` | Longitud en texto (multibyte), elementos en arrays, valor en números — ojo con el matiz de abajo |
| `#[In([...])]` | `in` | Lista cerrada de valores |
| `#[Regex('/.../')]` | `regex` | |
| `#[NoHtml]` | `no_html` | Prohíbe `<`, `>` y caracteres de control |
| `#[Confirmed]` | `confirmed` | Compara con `{campo}_confirmation` |
| `#[DateFormat('Y-m-d')]` | `date` | Sin formato, acepta cualquier fecha parseable |
| `#[Custom(MiValidador::class)]` | `custom` | Tu propia clase validadora |

**Además:**

- `rules()` y `messages()` sobrescribibles para reglas dinámicas o mensajes propios.
- `casts()` convierte un sub-array en otro DTO anidado.
- `toArray()`, `toJson()`, `only([...])`, `except([...])`, `has('campo')`.
- `fromArray($data)` y `fromRequest($request)` para construirlo a mano.
- Propiedades `readonly` soportadas (se asignan con closure bound, sin reflexión).

> ⚠️ **`min`/`max` miran el TIPO del valor, no la regla.** Un texto se mide
> siempre por longitud, aunque contenga dígitos: `'20'` con `min:18` falla
> («al menos 18 caracteres»), mientras que `20` pasa. Es deliberado —así un
> teléfono `'+5219381040076'` no se compara como número— pero muerde cuando los
> datos llegan de un formulario `application/x-www-form-urlencoded`, donde
> **todo es texto**. Con JSON los números llegan como números y no hay problema.

---

## 7. Validación sin DTO

Para casos sueltos, sin declarar una clase:

```php
$data = $request->validate([
    'email'    => 'required|email',
    'nombre'   => 'required|string|min:2|max:80',
    'edad'     => 'nullable|int',
    'rol'      => 'required|in:admin,user',
    'desde'    => 'nullable|date:Y-m-d',
    'password' => 'required|min:8|confirmed',
], [
    'email.required' => 'Necesitamos tu correo.',   // mensaje propio
]);
```

Devuelve **solo los campos declarados** (filtra lo que no pediste, que es lo que
quieres antes de un INSERT) y lanza una `ValidationException` que el Router
traduce a 422. Aplica el mismo matiz de `min`/`max` con textos numéricos que los
DTOs.

Reglas disponibles: `required`, `nullable`, `string`, `int`/`integer`,
`numeric`/`float`, `boolean`/`bool`, `email`, `url`, `array`, `min:n`, `max:n`,
`in:a,b,c`, `regex:/…/`, `no_html`, `confirmed`, `date[:formato]`,
`custom:Clase`.

---

## 8. Middlewares

Un middleware envuelve la petición y decide si sigue:

```php
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeaders(['X-Request-Id' => bin2hex(random_bytes(8))]);
    }
}
```

- **Globales:** `$router->addGlobalMiddleware(Clase::class, priority: 1)`. Menor
  prioridad = más externo. Envuelven también los 404 y los errores, que es
  justamente lo que necesita CORS para que el navegador no convierta un 401 en
  un opaco «Failed to fetch».
- **De ruta:** `#[Middleware(Clase::class, priority: 50)]` sobre la clase o el método.

**Incluidos:**

| Middleware | Qué hace |
|---|---|
| `CorsMiddleware` | Allowlist de orígenes; con credenciales **nunca** refleja un Origin arbitrario ni usa `*`. `CorsMiddleware::fromEnv()` lo configura con `FRONTEND_ORIGINS` |
| `AuthMiddleware` | Valida la sesión, publica `$request->user()` y **renueva el access token de forma transparente** con el refresh |
| `CsrfMiddleware` | Double-submit cookie en métodos de escritura; solo exige cabecera si existe la cookie, así puede ser global sin romper el login |
| `SecurityHeadersMiddleware` | `default-src 'none'`, `nosniff`, `X-Frame-Options`, `Referrer-Policy` y HSTS opcional |

---

## 9. Guards

Un guard decide **antes** de llegar al controlador. Se activa por un atributo
propio, que hay que declarar en `guardAttributes` del Router:

```php
final class OnlyOfficeHours implements GuardInterface
{
    public function canActivate(Request $request): bool|Response
    {
        return (int) date('G') < 18
            ? true
            : Response::json(['error' => 'Cerrado'], 423);   // respuesta propia
    }
}
```

- Devolver `true` deja pasar; `false` produce el rechazo genérico; devolver una
  `Response` permite explicar el porqué con el código y el cuerpo que quieras.
- Los parámetros del atributo llegan al guard como atributos del request
  (`_guard_permissions`, `_guard_roles`, `_guard_limit`…).
- Los guards se ejecutan por prioridad (`#[Throttle]` es 5, `#[Permission]` 10):
  primero se corta el abuso, después se comprueban los permisos.

---

## 10. Autenticación y RBAC

El kit completo se registra con una línea y lee toda su configuración del
entorno:

```php
(new AuthServiceProvider($container))->register();
```

**Endpoints que quedan servidos** (prefijo `/auth`):

| Método | Ruta | |
|---|---|---|
| `POST` | `/register` | Alta + correo de verificación + auto-login |
| `POST` | `/login` | Con «recordarme» |
| `POST` | `/google` | «Continuar con Google»: entra o da de alta |
| `POST` | `/logout` | Cierra sesión y revoca los tokens |
| `POST` | `/refresh` | Renueva el access token |
| `GET` `PATCH` | `/me` | Perfil propio |
| `POST` | `/verify-email` · `/resend-verification` | Verificación de correo |
| `POST` | `/forgot-password` · `/reset-password` | Restablecimiento |
| `POST` | `/change-password` | Pide la contraseña actual |
| `POST` `DELETE` | `/me/google` | Vincular / desvincular |
| `GET` | `/health` | Estado de la API y la BD |

**Lo que resuelve, y que casi siempre sale mal a mano:**

- **Refresco transparente.** El access token dura 15 min; el middleware acuña
  uno nuevo desde el refresh sin que el front gestione nada.
- **Revocación real** por marca de agua (`tokens_valid_after`): cambiar la
  contraseña invalida todas las sesiones anteriores al instante, sin listas
  negras de `jti`, y el dispositivo actual no se cae.
- **Anti-enumeración**: correo inexistente y contraseña incorrecta dan la misma
  respuesta y tardan lo mismo (`#[TimeAlwaysExecuted]` iguala los tiempos).
- **Tokens de un solo uso** con expiración para verificar y restablecer.
- **JWT propio** (HS256) con claves separadas para access y refresh; se niega a
  arrancar si faltan o coinciden.
- **Google** verificado contra el JWKS con `openssl`, sin librerías de JWT.

**RBAC:**

```php
#[Roles('admin')]                              // basta uno de los roles
#[Permission('users:read')]                    // permiso concreto
#[Permission('users:read', 'reports:read')]    // TODOS los que pases
#[Permission('users:write|users:delete')]      // alternativas: basta una
#[Throttle(5, 60)]                             // 5 peticiones por minuto e IP
```

Con `AUTH_SUPER_ROLE=superadmin`, quien tenga ese rol salta la comprobación de
permisos puntuales. Vacío (por defecto) desactiva el atajo.

Un 403 dice **qué falta** (`"missing": ["reports:read"]`): quien pregunta ya está
autenticado, así que no filtra nada y ahorra una tarde de depuración al front.

**El esquema** está en [`src/Auth/migrations/`](../src/Auth/migrations/):
`auth_pgsql.sql` / `auth_mysql.sql` crean tablas, roles, permisos y semillas;
`seed_user_pgsql.sql` deja un administrador listo para entrar.

**Conectarlo a tu tabla de usuarios:** implementa
`UserRepositoryInterface` contra ella y regístrala *después* del provider. El
resto del kit —casos de uso, controlador, middlewares— no se toca.

---

## 11. Base de datos

```php
$manager = DatabaseManager::fromEnv();   // descubre conexiones del entorno
$db      = $manager->connection();       // se abre en la PRIMERA consulta

$rows = $db->query('SELECT * FROM users WHERE active = :a', ['a' => true])->fetchAll();
$one  = $db->query('SELECT * FROM users WHERE id = ?', [$id])->fetch();
$n    = $db->query('DELETE FROM logs WHERE created_at < ?', [$fecha])->rowCount();

$db->transaction(function ($db) {        // commit/rollback automáticos
    $db->query('INSERT ...');
    $db->query('UPDATE ...');
});
```

| Capacidad | Detalle |
|---|---|
| Motores | PostgreSQL, MySQL/MariaDB, SQLite |
| Prepared statements | Reales (`ATTR_EMULATE_PREPARES=false`): la inyección SQL se cierra en el driver |
| Conexiones múltiples | `database.default`, `database.mysql`… y `#[Inject('database.replica')]` |
| Apertura perezosa | Una petición que no consulta nada no abre ninguna conexión |
| Resultados | `fetch()`, `fetchAll()`, `rowCount()`, e iterable directamente |
| Errores | Si falta el driver PDO, el mensaje lo dice; no un críptico "class not found" |

No hay ORM ni query builder: se escribe SQL. Es una decisión, no una carencia
pendiente — ver [§20](#20-lo-que-no-hace).

---

## 12. Caché

```php
$cache = $container->get(CacheInterface::class);   // Redis si está configurado
$cache->set('clave', $json, ttl: 300);
$cache->get('clave');
$cache->increment('contador', ttl: 60);
$cache->isAvailable();
```

- `RedisCache` habla con `ext-redis` **o** con `predis/predis`, el que tengas.
- Si Redis se cae, **degrada a no-op** en vez de tumbar la petición.
- Sin `REDIS_HOST`, `ArrayCache`: memoria del proceso.

> **Importante en share-nothing:** el `ArrayCache` muere con la petición. Sirve
> para memorizar dentro de una misma petición y para que el código no reviente en
> desarrollo, **no** para contadores compartidos. El rate limiting solo cuenta de
> verdad con Redis.

---

## 13. Correo

```php
$mailer = $container->get(MailerInterface::class);
$mailer->send('ada@example.com', 'Asunto', '<p>Hola</p>');
$mailer->isConfigured();
```

| Implementación | Se activa con | Notas |
|---|---|---|
| `SmtpMailer` | `MAIL_HOST` | Cliente SMTP nativo con STARTTLS/SMTPS y AUTH, sin dependencias |
| `ResendMailer` | `RESEND_API_KEY` | API de Resend |
| `LogMailer` | nada configurado | El mensaje completo va al log: se desarrolla sin remitente |

En producción, `AuthServiceProvider::boot()` avisa a gritos si no hay mailer:
sin él, nadie recibe la verificación ni el restablecimiento, y eso se descubre
tarde y mal.

---

## 14. Logging

PSR-3. Con `monolog/monolog` instalado, `LoggerFactory::create($logDir)` devuelve
un Monolog; si no, el `SimpleLogger` incluido (cero dependencias).

```php
$container->setFactory(LoggerInterface::class, fn() => LoggerFactory::create(__DIR__ . '/../var/logs'));
```

`reportError($logger, $e)` registra una excepción con el contexto ya extraído
(el detalle campo por campo si es de validación, el mensaje y `file:line` si no).

El Router registra por su cuenta **solo los 5xx**: un 401 de credenciales o un
422 de enlace ya usado son comportamiento normal de los usuarios, y llenar el log
con ellos entierra los fallos de verdad.

---

## 15. Seguridad

Lo que el framework hace por ti, agrupado:

| Frente | Qué trae |
|---|---|
| **Inyección SQL** | Prepared statements reales en todas las consultas |
| **XSS almacenado** | `#[NoHtml]` en los DTOs; las respuestas son JSON |
| **CSRF** | `CsrfMiddleware` double-submit, con las pantallas públicas exentas |
| **CORS** | Allowlist estricta; con credenciales nunca `*` ni reflejo del Origin |
| **Clickjacking / sniffing** | `SecurityHeadersMiddleware` (CSP `default-src 'none'`, `nosniff`, `X-Frame-Options`) |
| **Fuerza bruta** | `#[Throttle]` por IP y ruta (con Redis, global) |
| **Enumeración de cuentas** | Respuestas y tiempos indistinguibles en login y forgot-password |
| **Robo de sesión** | Cookies `HttpOnly`; revocación por marca de agua; claves separadas access/refresh |
| **Suplantación de IP** | `X-Forwarded-For` solo se cree desde proxies declarados |
| **Host header injection** | `url()` usa `APP_URL` o una allowlist; si no, degrada a ruta relativa |
| **Secretos en reposo** | `EncryptionService` (XSalsa20-Poly1305, autenticado) |
| **Contraseñas** | `PasswordPolicy` configurable, compartida por registro, reset y cambio |
| **Fugas en errores** | En producción, el mensaje interno de una excepción no sale al cliente |

```php
$enc = $container->get(EncryptionService::class);
$fila->smtp_password = $enc->encrypt($appPassword);
$appPassword = $enc->decrypt($fila->smtp_password);   // null si fue manipulado
```

---

## 16. Errores y códigos HTTP

| Código | Cuándo | Quién lo produce |
|---|---|---|
| `401` | Sin sesión o token inválido/expirado | `AuthMiddleware`, guards |
| `403` | Autenticado pero sin rol/permiso (con `missing`) | `RoleGuard`, `PermissionGuard` |
| `404` | Ruta no encontrada | Router (pasando por los middlewares globales) |
| `422` | DTO o `validate()` no cumplen | Router, desde `ValidationException` |
| `429` | Límite de peticiones (con `X-RateLimit-*` y `Retry-After`) | `ThrottleGuard` |
| `4xx` a medida | `throw new HttpException('CODE', 'mensaje', 409)` | Tu controlador |
| `500` | Excepción no controlada | Router (mensaje genérico en producción) |

Para las excepciones de librerías de terceros:
`$router->registerExceptionHandler(SuExcepcion::class, fn($e) => Response::json(...))`.

---

## 17. Rendimiento en producción

Con `isProduction: true` y OPcache activo:

- **Rutas** y **metadata del contenedor** se serializan a archivos `.php` que
  OPcache mantiene compilados en memoria: sin escaneo de atributos ni reflexión
  por petición. La escritura es atómica (tmp + rename), así que un despliegue no
  deja a nadie leyendo un archivo a medias.
- **Rutas estáticas** en O(1); las dinámicas, un solo `preg_match` para todas.
- **Todo perezoso**: base de datos, Redis y mailer no se instancian si la
  petición no los usa.
- **Reglas de validación** parseadas una vez y cacheadas en estático.

Borra `var/cache/*.php` en cada despliegue.

---

## 18. Pruebas

`Router::handle()` devuelve la `Response` sin enviarla: se puede ejercitar el
flujo HTTP completo —middlewares globales, guards, middlewares de ruta y
controlador— dentro de PHPUnit, sin servidor ni `curl`.

```php
$response = $router->handle(new Request([], $body, $server, [], $container));

$this->assertSame(201, $response->getStatusCode());
$this->assertArrayHasKey('access_token', $response->getCookies());
```

El propio kit de auth se prueba así, con un `UserRepositoryInterface` en memoria
y sin base de datos (ver `tests/Auth/`).

---

## 19. Variables de entorno

Resumen de las que activan capacidades. El [`.env.example`](../.env.example) las
documenta todas una por una.

| Variable | Activa |
|---|---|
| `APP_ENV=production` | Modo producción: cachés, cookies endurecidas, errores opacos |
| `APP_URL`, `APP_TRUSTED_HOSTS` | Generación segura de URLs absolutas |
| `DB_HOST` + `DB_NAME` | Conexión principal (driver deducido del puerto) |
| `MYSQL_*`, `PGSQL_*` | Conexiones adicionales |
| `REDIS_HOST` o `REDIS_URL` | Caché y rate limiting compartidos |
| `MAIL_HOST` / `RESEND_API_KEY` | Envío de correo (si no, va al log) |
| `JWT_SECRET`, `JWT_REFRESH_SECRET` | Autenticación (obligatorias y distintas) |
| `FRONTEND_ORIGINS` | CORS con credenciales y enlaces de los correos |
| `AUTH_REQUIRE_VERIFIED_EMAIL` | Verificación dura de correo |
| `AUTH_DEFAULT_ROLES`, `AUTH_SUPER_ROLE` | Roles al registrarse; rol con acceso total |
| `GOOGLE_CLIENT_ID` | «Continuar con Google» |
| `RECAPTCHA_SECRET` | reCAPTCHA en login y registro |
| `APP_ENCRYPTION_KEY` | Cifrado de secretos en reposo |
| `PASSWORD_*` | Política de contraseñas |

---

## 20. Lo que NO hace

Decirlo ahorra tiempo a quien evalúa el framework:

- **No hay ORM ni query builder.** Se escribe SQL contra `DatabaseInterface`.
- **No hay sistema de migraciones.** Los `.sql` del kit de auth son idempotentes
  y se aplican a mano o con la herramienta que ya uses.
- **No hay plantillas ni assets.** Es para APIs; el front va aparte.
- **No hay colas, cron ni jobs en segundo plano.**
- **No hay WebSockets ni SSE**: el modelo es síncrono y share-nothing.
- **No hay panel de administración ni scaffolding/CLI.**
- **No hay multi-tenancy** más allá de lo que resuelvas en tus repositorios.

Casi todo eso se resuelve con una librería del ecosistema PHP y una factoría en
el contenedor. Lo que el framework promete es el camino corto de una petición
HTTP a un controlador con datos ya validados y un usuario ya autenticado.
