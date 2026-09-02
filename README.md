# HexaLite

**Micro-framework PHP moderno, síncrono y sin magia.** Enrutado por atributos,
contenedor de inyección de dependencias con _autowiring_, DTOs autovalidados y un
motor de validación nativo — todo con una superficie de dependencias mínima.

[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

HexaLite está pensado para el modelo **share-nothing de PHP-FPM** (un proceso por
petición): cero riesgo de _data bleeding_ entre requests y un arranque casi
instantáneo gracias al cacheo de rutas y metadatos en producción.

---

## ✨ Características

- **Rutas por atributos** — `#[Route]`, `#[Controller]`, `#[Middleware]` sobre los
  métodos del controlador. Rutas estáticas resueltas en O(1) y dinámicas con un
  mega-regex compilado (patrón FastRoute).
- **Inyección de dependencias** — contenedor propio con _autowiring_ de
  constructores, singletons, factorías _lazy_, servicios _transient_ y detección
  de dependencias circulares.
- **DTOs autovalidados** — declara las reglas como atributos PHP; el Router
  hidrata y valida el DTO automáticamente y responde `422` si algo falla.
- **Validación nativa** — al estilo Laravel con `$request->validate([...])`, sin
  dependencias pesadas.
- **Middlewares y Guards** — pipeline con prioridades; Guards por atributo para
  roles, permisos o _throttling_.
- **PSR-3 logging** — `SimpleLogger` incluido (cero dependencias) o Monolog si lo
  instalas.
- **Autenticación de fábrica** — login, registro, verificación de correo,
  restablecer/cambiar contraseña, Google, roles y permisos. JWT propio (HS256),
  sin dependencias. Con su SQL para PostgreSQL y MySQL.
- **Conexiones que se activan solas** — PostgreSQL, MySQL y Redis se encienden si
  sus variables de entorno están llenas, y se abren de forma perezosa.
- **Correo incluido** — cliente SMTP nativo o API de Resend; sin configurar, los
  mensajes van al log para poder desarrollar sin remitente.
- **Secretos cifrados en reposo** — `EncryptionService` (XSalsa20-Poly1305) para
  guardar credenciales de terceros sin dejarlas en claro en la base de datos.
- **Sin extensiones PECL obligatorias** — se instala solo con PHP + ext estándar.

## 📦 Requisitos

- PHP **8.2+**
- Extensiones: `ext-json`, `ext-mbstring` (incluidas por defecto en PHP)
- `ext-pdo` + `pdo_pgsql`/`pdo_mysql` si usas la base de datos o el kit de auth
- Opcionales: `ext-redis` (o `predis/predis`) para caché compartida,
  `ext-openssl` para el login con Google, `ext-curl` para acelerar las llamadas
  salientes

## 🚀 Instalación

```bash
composer require hexalite/framework
```

## ⚡ Arranque (front controller)

Un único `public/index.php` arranca el contenedor, registra los controladores y
despacha la petición:

```php
<?php
declare(strict_types=1);

use HexaLite\Container\Container;
use HexaLite\Http\Request;
use HexaLite\Http\Router;
use App\Controllers\HelloController;
use App\Controllers\UserController;

require __DIR__ . '/../vendor/autoload.php';

loadEnv(__DIR__ . '/../.env');                    // helper opcional
$isProduction = env('APP_ENV', 'local') === 'production';

// 1) Contenedor DI (cachea metadatos de constructores en producción)
$container = new Container(
    cacheFile:    __DIR__ . '/../var/cache/container.php',
    isProduction: $isProduction,
);

// 2) Router: recibe las CLASES de controladores a escanear por atributos
$router = new Router(
    controllers:  [HelloController::class, UserController::class],
    container:    $container,
    cacheFile:    __DIR__ . '/../var/cache/routes.php',
    isProduction: $isProduction,
);

// 3) Despachar la petición actual
$router->dispatch(Request::createFromGlobals($container));
```

Sírvelo con cualquier SAPI. Para desarrollo:

```bash
php -S localhost:8000 -t public
```

## 🧭 Un controlador

```php
namespace App\Controllers;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Route;
use HexaLite\Http\Response;

#[Controller('/users')]                 // prefijo de ruta para toda la clase
class UserController
{
    // Autowiring: el contenedor resuelve UserRepository e lo inyecta
    public function __construct(private UserRepository $users) {}

    #[Route('', method: 'GET')]
    public function list(): Response
    {
        return response(['data' => $this->users->all()]);
    }

    // El parámetro {id} se castea al tipo declarado (int) y se inyecta
    #[Route('/{id}', method: 'GET')]
    public function show(int $id): Response
    {
        $user = $this->users->find($id);

        if (!$user) {
            // El Router convierte HttpException en la Response JSON correspondiente
            throw new \HexaLite\Http\HttpException('USER_NOT_FOUND', "No existe el usuario {$id}", 404);
        }

        return response(['data' => $user]);
    }
}
```

> ⚠️ **Orden de rutas:** declara las rutas estáticas (`/users/datatable`) antes que
> las dinámicas (`/users/{id}`) para evitar que la dinámica capture a la estática.

## 🧱 DTOs autovalidados

Extiende `Dtos` y declara las reglas como atributos. Si tipas el DTO en la firma
del método, el Router lo **hidrata y valida** automáticamente:

```php
namespace App\Dtos;

use HexaLite\Http\DTO\Dtos;
use HexaLite\Http\DTO\Attributes\{IsRequired, IsString, IsEmail, Min};

class CreateUserDto extends Dtos
{
    #[IsRequired] #[IsString] #[Min(2)]
    public string $name;

    #[IsRequired] #[IsEmail]
    public string $email;
}
```

```php
#[Route('', method: 'POST')]
public function create(CreateUserDto $dto): Response
{
    // Si el body no cumple las reglas → el Router responde 422 automáticamente.
    // Aquí $dto ya está validado y tipado.
    $user = $this->users->create($dto->toArray());

    return response(['data' => $user], 201);
}
```

Atributos de validación disponibles: `IsRequired`, `IsString`, `IsInt`, `IsFloat`,
`IsNumeric`, `IsBoolean`, `IsEmail`, `IsUrl`, `IsArray`, `Min`, `Max`, `In`,
`Regex`, `NoHtml`, `Confirmed`, `DateFormat`, `Nullable` y `Custom`.

### Validación rápida (sin DTO)

```php
$data = $request->validate([
    'email'    => 'required|email',
    'password' => 'required|min:8',
]);
```

## 🔌 Servicios e inyección de dependencias

El contenedor resuelve dependencias de forma recursiva. Para servicios de terceros
(o con configuración), regístralos con una factoría _lazy_, típicamente desde un
_provider_:

```php
use HexaLite\Providers\ProviderInterface;

final class AppServiceProvider implements ProviderInterface
{
    public function __construct(private \HexaLite\Container\Container $container) {}

    public function register(): void
    {
        // Factoría lazy: solo se construye si alguien la inyecta
        $this->container->setFactory(
            MailerService::class,
            fn($c) => new MailerService(env('MAIL_DSN')),
        );

        // Interfaz → implementación concreta
        $this->container->bind(UserRepositoryInterface::class, PdoUserRepository::class);
    }

    public function boot(): void {}
}
```

## 🧩 Middlewares

```php
use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $id = bin2hex(random_bytes(8));
        $request->setAttribute('request_id', $id);

        $response = $next($request);                 // continúa la cadena

        return $response->withHeaders(['X-Request-Id' => $id]);
    }
}
```

Aplícalos por ruta/clase con `#[Middleware(RequestIdMiddleware::class, priority: 1)]`
o globalmente con `$router->addGlobalMiddleware(RequestIdMiddleware::class, 1)`.

## 🛡️ Guards por atributo

Un Guard decide si una petición puede continuar. Se activa mediante un atributo
propio que registras como `guardAttributes` del Router:

```php
use HexaLite\Http\Domain\GuardInterface;
use HexaLite\Http\Request;

final class RolesGuard implements GuardInterface
{
    public function canActivate(Request $request): bool
    {
        $roles = (array) $request->getAttribute('_guard_roles', []);
        $user  = $request->user();                   // publicado por tu middleware de auth

        return $user !== null && in_array($user->role, $roles, true);
    }
}
```

```php
$router = new Router(
    controllers:     [AdminController::class],
    container:       $container,
    cacheFile:       __DIR__ . '/../var/cache/routes.php',
    isProduction:    $isProduction,
    guardAttributes: [App\Attributes\Roles::class],  // atributos interceptables
);
```

## 🔐 Autenticación incluida

HexaLite trae un **kit de autenticación completo y funcionando**: alta, inicio de
sesión, verificación de correo, olvidé/restablecer/cambiar contraseña, «Continuar
con Google», roles y permisos. Sin dependencias añadidas — el JWT (HS256) y el
cliente SMTP están escritos dentro del framework.

Tres pasos:

```bash
# 1. Esquema (elige el de tu motor). Es idempotente.
psql "$DATABASE_URL" -f vendor/hexalite/framework/src/Auth/migrations/auth_pgsql.sql
# mysql -u user -p base < vendor/hexalite/framework/src/Auth/migrations/auth_mysql.sql

# 1b. Opcional (PostgreSQL): un administrador ya creado y verificado, para probar
#     las rutas protegidas sin pasar por el registro. admin@example.com / CambiaEsto1!
psql "$DATABASE_URL" -f vendor/hexalite/framework/src/Auth/migrations/seed_user_pgsql.sql

# 2. Dos claves distintas en el .env
php -r 'echo "JWT_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;'
php -r 'echo "JWT_REFRESH_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;'
```

```php
// 3. Registrar el provider y pasar sus controladores y atributos al Router.
use HexaLite\Auth\AuthServiceProvider;

(new AuthServiceProvider($container))->register();

$router = new Router(
    controllers:     [...AuthServiceProvider::controllers(), MiControlador::class],
    container:       $container,
    cacheFile:       __DIR__ . '/../var/cache/routes.php',
    isProduction:    $isProduction,
    guardAttributes: AuthServiceProvider::guardAttributes(),
);
```

Con eso quedan servidos:

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/auth/register` | Alta + correo de verificación + auto-login |
| `POST` | `/auth/login` | Inicio de sesión (con «recordarme») |
| `POST` | `/auth/google` | «Continuar con Google»: entra o da de alta |
| `POST` | `/auth/logout` | Cierra sesión y revoca los tokens |
| `POST` | `/auth/refresh` | Renueva el access token |
| `GET`/`PATCH` | `/auth/me` | Perfil propio |
| `POST` | `/auth/verify-email` | Consume el token del enlace |
| `POST` | `/auth/resend-verification` | Reenvía el correo |
| `POST` | `/auth/forgot-password` | Envía el enlace de restablecimiento |
| `POST` | `/auth/reset-password` | Fija la contraseña nueva |
| `POST` | `/auth/change-password` | Cambia la propia (pide la actual) |
| `POST`/`DELETE` | `/auth/me/google` | Vincula / desvincula Google |
| `GET` | `/auth/health` | Estado de la API y la BD |

Y en tus propios controladores:

```php
use HexaLite\Auth\Attributes\{Permission, Roles, Throttle};
use HexaLite\Auth\Middlewares\{AuthMiddleware, CsrfMiddleware};

#[Route('/reports', 'GET')]
#[Middleware(AuthMiddleware::class)]
#[Permission('reports:read')]
#[Throttle(30, 60)]
public function index(Request $request): Response
{
    $userId = $request->user()->sub;    // JwtPayload publicado por el middleware
    // …
}
```

Un `#[Permission]` exige **todos** los permisos que le pases, pero cada uno
admite **alternativas** separadas por `|`, de las que basta cumplir una — lo que
necesita un recurso de referencia al que llegan varios flujos:

```php
#[Permission('vehiculos:ver|cotizaciones:ver')]     // con cualquiera de los dos entra
#[Permission('billing:read', 'billing:write')]      // aquí hacen falta los dos
```

Y si defines `AUTH_SUPER_ROLE=superadmin` en el `.env`, quien tenga ese rol pasa
sin revisar permisos puntuales. Vacío (por defecto) desactiva el atajo y ahorra
la consulta de roles.

**Lo que el kit resuelve por ti** (y suele salir mal cuando se escribe a mano):

- **Refresco transparente.** El access token dura 15 min; el middleware lo renueva
  solo. El front no gestiona nada.
- **Anti-enumeración.** Un correo que no existe y una contraseña incorrecta dan la
  misma respuesta, con tiempos parecidos. `forgot-password` siempre responde 200.
- **Revocación real.** Cambiar la contraseña o cerrar sesión invalida al instante
  todos los tokens anteriores, sin esperar a que expiren; el dispositivo actual
  recibe credenciales nuevas y no se cae.
- **CSRF por double-submit**, con las pantallas públicas de verificación y reset
  exentas (su secreto va en el cuerpo, no en la cookie).
- **Tokens de un solo uso** con expiración para verificar y restablecer.

El contrato completo para quien programe el cliente está en
[`docs/FRONTEND.md`](docs/FRONTEND.md).

### Conectarlo a tu propia tabla de usuarios

El módulo depende de una interfaz, no del esquema. Implementa
`HexaLite\Auth\Domain\UserRepositoryInterface` contra tus tablas y enlázala
**después** de registrar el provider:

```php
$container->set(UserRepositoryInterface::class, new MiRepositorioDeUsuarios($db));
```

Todo lo demás —casos de uso, controlador, middlewares— sigue funcionando igual.

### Mapear excepciones de terceros

El core reconoce de fábrica `ValidationException` (→ 422) y `HttpException`
(→ status configurable). Para las de una librería externa, sin acoplar el
framework:

```php
$router->registerExceptionHandler(
    \Alguna\Libreria\TokenExpirado::class,
    fn($e) => Response::json(['error' => 'Unauthorized'], 401),
);
```

## 🗄️ Base de datos, Redis y correo — se activan solos

El principio es el mismo en las tres: **si llenas sus variables, la pieza se
enciende; si no, el framework arranca igual** con una alternativa que no rompe.

```php
use HexaLite\Database\DatabaseManager;

$manager = DatabaseManager::fromEnv();   // descubre las conexiones del entorno
$db      = $manager->connection();       // se abre en la PRIMERA consulta, no antes
```

| Variables | Se activa | Si faltan |
|---|---|---|
| `DB_HOST` + `DB_NAME` | Conexión `default` (driver deducido del puerto) | No se registra ninguna conexión |
| `MYSQL_*` / `PGSQL_*` | Conexiones extra (`database.mysql`, `database.pgsql`) | — |
| `REDIS_HOST` o `REDIS_URL` | Caché y rate limiting **compartidos** | Caché en memoria del proceso |
| `MAIL_HOST` | SMTP nativo (sin dependencias) | — |
| `RESEND_API_KEY` | Envío por la API de Resend | — |
| ninguna de correo | — | Los correos se escriben en el log |
| `GOOGLE_CLIENT_ID` | «Continuar con Google» | El botón queda apagado |
| `RECAPTCHA_SECRET` | reCAPTCHA obligatorio en login/registro | No se verifica |

Redis habla con `ext-redis` o con `predis/predis`, el que tengas; si se cae, la
caché degrada a no-op en vez de tumbar la petición. El `.env.example` documenta
todas las variables.

Para conectarte a mano sigue estando `PDODatabase`:

```php
$db = new PDODatabase('pgsql:host=127.0.0.1;dbname=app', 'user', 'secret');
$users = $db->query('SELECT * FROM users WHERE active = :a', ['a' => true]);
```

## 🔒 Secretos cifrados en reposo

Cuando tu app guarda un secreto de terceros —el App Password del SMTP del
usuario, el token de una integración— no basta con "la base de datos es
privada". `EncryptionService` cifra con XSalsa20-Poly1305 (libsodium):

```php
use HexaLite\Security\EncryptionService;

$enc = $container->get(EncryptionService::class);   // registrado por AuthServiceProvider

$row->smtp_password = $enc->encrypt($appPassword);  // esto es lo que va a la BD
$appPassword        = $enc->decrypt($row->smtp_password);
```

Es cifrado **autenticado**: un texto manipulado no se descifra a basura, falla —
`decrypt()` devuelve `null` y nunca datos alterados. El nonce es aleatorio en
cada llamada, así que dos filas con el mismo secreto no se delatan por ser
iguales. La clave se deriva de `APP_ENCRYPTION_KEY` (o de `JWT_SECRET` como
respaldo) con BLAKE2b, de modo que cualquier cadena sirve como secreto.

> No es para contraseñas de acceso: esas se hashean, no se cifran. Y la clave no
> puede cambiar mientras existan datos cifrados con ella.

## ⚙️ Rendimiento en producción

Con `APP_ENV=production`:

- El Router compila las rutas por Reflection **una sola vez** y las guarda como PHP
  plano en `var/cache/routes.php` (aprovecha OPcache, 0 I/O en requests siguientes).
- El contenedor cachea los metadatos de constructores en `var/cache/container.php`.

Si cambias controladores o firmas, borra el caché:

```bash
rm -f var/cache/routes.php var/cache/container.php
```

## 🧪 Pruebas

```bash
composer test
```

Las pruebas de integración del kit de auth corren contra motores reales y se
**saltan solas** si no hay ninguno configurado. Para ejecutarlas en local:

```bash
docker run -d --name hx-pg -e POSTGRES_USER=hx -e POSTGRES_PASSWORD=hx \
    -e POSTGRES_DB=hxtest -p 55432:5432 postgres:16-alpine
docker run -d --name hx-my -e MYSQL_ROOT_PASSWORD=hx -e MYSQL_DATABASE=hxtest \
    -p 53306:3306 mysql:8
docker run -d --name hx-redis -p 56379:6379 redis:7-alpine

psql "postgresql://hx:hx@127.0.0.1:55432/hxtest" -f src/Auth/migrations/auth_pgsql.sql
mysql -h 127.0.0.1 -P 53306 -uroot -phx hxtest < src/Auth/migrations/auth_mysql.sql

HEXALITE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=hxtest' \
HEXALITE_TEST_PGSQL_USER=hx HEXALITE_TEST_PGSQL_PASSWORD=hx \
HEXALITE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=53306;dbname=hxtest' \
HEXALITE_TEST_MYSQL_USER=root HEXALITE_TEST_MYSQL_PASSWORD=hx \
HEXALITE_TEST_REDIS_HOST=127.0.0.1 HEXALITE_TEST_REDIS_PORT=56379 \
composer test
```

## 📂 Estructura sugerida de una app

```
mi-app/
├── public/index.php        # front controller
├── src/                    # tu código (App\…): Controllers, Dtos, Services…
├── var/cache/              # rutas + contenedor compilados (producción)
├── .env
└── composer.json
```

El framework vive en `vendor/hexalite/framework` (namespace `HexaLite\`); tú solo
escribes tu aplicación.

Hay dos ejemplos ejecutables en [`examples/`](examples/): una mini-API y otro con
el kit de autenticación completo.

## 📚 Documentación

- [`docs/CAPACIDADES.md`](docs/CAPACIDADES.md) — catálogo completo: qué hace cada
  pieza, el ciclo de una petición, todas las reglas de validación y **dónde está
  el límite de cada capacidad**.
- [`docs/FRONTEND.md`](docs/FRONTEND.md) — contrato para el cliente: cookies,
  CSRF, endpoints, códigos de error y un cliente JS copiable.
- [`.env.example`](.env.example) — todas las variables, con qué activa cada una.
- [`src/Auth/migrations/`](src/Auth/migrations/) — esquema SQL para PostgreSQL y
  MySQL, más `seed_user_pgsql.sql` para arrancar con un administrador creado.
- [`examples/`](examples/) — dos apps ejecutables: la mini-API y el kit de auth
  con rutas protegidas por rol y permiso.

## 📄 Licencia

HexaLite es software libre bajo la licencia [MIT](LICENSE).
