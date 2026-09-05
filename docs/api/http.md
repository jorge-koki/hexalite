# API — HTTP

[← Índice de la referencia](README.md)

Núcleo del framework: la petición, la respuesta, el router que las une y los
contratos que se enchufan en medio.

- [`Request`](#request) · [`Response`](#response) · [`ResponseFactory`](#responsefactory)
- [`Body`](#body) · [`Params`](#params)
- [`Router`](#router)
- [`HttpException`](#httpexception) · [`ValidationException`](#validationexception)
- [`MiddlewareInterface`](#middlewareinterface) · [`GuardInterface`](#guardinterface)
- [`CorsMiddleware`](#corsmiddleware)
- [Atributos de ruta](#atributos-de-ruta): [`Route`](#route) · [`Controller`](#controller) · [`Middleware`](#middleware) · [`TimeAlwaysExecuted`](#timealwaysexecuted)
- [Helpers globales](#helpers-globales)

---

## Request

`HexaLite\Http\Request` — `src/Http/Request.php`

Petición HTTP entrante. Las propiedades son `readonly`; el estado que añaden
middlewares y guards vive en los *atributos* (`setAttribute()`/`getAttribute()`),
no en la petición misma.

### Construcción

```php
__construct(
    array $query, array $body, array $server, array $files,
    ?Container $container = null
)

static createFromGlobals(?Container $container = null): Request
```

`createFromGlobals()` es la vía normal. Lee `$_GET`, `$_POST`, `$_SERVER`, `$_FILES`
y `$_COOKIE`, y **parsea el JSON una sola vez**: si el `Content-Type` contiene
`application/json`, decodifica `php://input` con profundidad máxima 64. Un JSON
escalar o inválido se normaliza a `[]` — así la validación responde 422 en vez de
reventar con un `TypeError`.

### Propiedades públicas

| Propiedad | Tipo | Contenido |
|---|---|---|
| `$query` | `readonly array` | Query string (`$_GET`) |
| `$body` | `readonly array` | Cuerpo ya parseado (JSON o `$_POST`) |
| `$server` | `readonly array` | `$_SERVER` |
| `$files` | `readonly array` | `$_FILES` |

### Lectura de datos

| Método | Devuelve |
|---|---|
| `input(string $key, mixed $default = null): mixed` | Valor del body y, si no está, del query string. Los strings vienen con `trim()` aplicado. |
| `all(): array` | Query + body fusionados (el body gana). |
| `getBody(): array` | Solo el cuerpo. |
| `getQuery(): array` | Solo el query string. |

### Ruta, método y cliente

| Método | Devuelve |
|---|---|
| `getMethod(): string` | Verbo HTTP (`GET` si no viene). |
| `getPath(): string` | Path sin query string. Se parsea una vez y se memoiza. |
| `getIp(): string` | IP del cliente. |

`getIp()` **solo confía en las cabeceras de proxy si la petición viene de un proxy
declarado como de confianza**. Con `X-Forwarded-For`, recorre la lista de derecha a
izquierda y devuelve la primera IP que no sea de confianza — que es la del cliente
real. Sin proxies configurados devuelve siempre `REMOTE_ADDR`, y una cabecera
falsificada no puede saltarse un rate limit.

```php
static setTrustedProxies(array $proxies): void   // ['127.0.0.1', '10.0.0.0/8']
static getTrustedProxies(): array
```

Acepta IPs exactas y rangos CIDR (IPv4). Se configura una vez en el front controller.

### Atributos de la petición

```php
setAttribute(string $key, mixed $value): void
getAttribute(string $key, mixed $default = null): mixed
user(): mixed
```

El canal por el que los middlewares publican lo que resuelven. `user()` es azúcar
para `getAttribute('user')`.

El core **no decodifica tokens**: el patrón es que un middleware de auth valide la
credencial y publique el usuario con `setAttribute('user', $user)`. Los controladores
deben leerlo de ahí y no re-decodificar la cookie — cuando el
[`AuthMiddleware`](auth.md#authmiddleware) renueva un acceso caducado, la cookie del
*request* sigue siendo la vieja y volver a decodificarla lanzaría «token expirado».

El Router publica además `route_controller`, `route_method` y `_route_params`.

### Cabeceras y credenciales

| Método | Devuelve |
|---|---|
| `getHeader(string $name): ?string` | Cabecera por su nombre HTTP (`'Authorization'` → `HTTP_AUTHORIZATION`). |
| `getBearerToken(): ?string` | Token del `Authorization: Bearer`, sin el prefijo. |
| `getJwtToken(): ?string` | Cookie `access_token` y, como respaldo, el Bearer. Solo **extrae**: no valida ni decodifica. |
| `cookie(string $key, $default = null): mixed` | Cookie de la petición. |
| `hasCookie(string $key): bool` | ¿Vino esa cookie? |

### Ficheros

| Método | Devuelve |
|---|---|
| `getFile(string $key): ?array` | Entrada de `$_FILES`, o `null`. |
| `getFiles(): array` | Todas. |
| `hasFile(string $key): bool` | Existe **y** subió sin error (`UPLOAD_ERR_OK`). |
| `hasFiles(): bool` | ¿Hay al menos un fichero subido correctamente? |

### Validación

```php
validate(array $rulesMap, array $customMessages = []): array
```

Valida `all()` con [`Validator`](dto-validacion.md#validator) y devuelve **solo** los
campos declarados en `$rulesMap`. Lanza [`ValidationException`](#validationexception)
si algo falla, que el Router convierte en un 422.

```php
$datos = $request->validate([
    'email' => 'required|email',
    'edad'  => 'nullable|int|min:18',
], [
    'email' => ['required' => 'Necesitamos tu correo.'],
]);
```

### Otros

| Método | Devuelve |
|---|---|
| `getContainer(): ?Container` | Contenedor asociado, si se creó con uno. Útil para middlewares que resuelven servicios. |

---

## Response

`HexaLite\Http\Response` — `src/Http/Response.php`

Respuesta HTTP. Inmutable en la práctica: `withHeaders()` devuelve una instancia
nueva, `withCookie()` encola sobre la actual.

```php
__construct(mixed $content = '', int $statusCode = 200, array $headers = [])
static json(mixed $data, int $status = 200): Response
```

`json()` codifica con `JSON_UNESCAPED_UNICODE` y `Content-Type:
application/json; charset=utf-8`. Si el dato no es serializable, **no propaga la
excepción**: registra el error y devuelve un 500 mínimo, para no filtrar estado
interno en la respuesta.

| Método | Efecto |
|---|---|
| `withCookie(string $name, string $value, array $options = []): Response` | Encola una cookie. Devuelve `$this`. |
| `withHeaders(array $headers): Response` | **Nueva** instancia con las cabeceras fusionadas, arrastrando la cola de cookies. |
| `send(): void` | Emite código, cookies, cabeceras y cuerpo. |
| `getHeaders(): array` | Cabeceras actuales. |
| `getContent(): mixed` | Contenido sin serializar. |
| `getStatusCode(): int` | Código de estado. |
| `getCookies(): array` | Cookies encoladas, indexadas por nombre. Para tests y runtimes que envían ellos la respuesta. |

**Opciones de `withCookie()`** — `expires` (epoch, `0` = cookie de sesión), `path`
(`/`), `domain` (`''`), `secure` (por defecto: `APP_ENV === 'production'`), `httponly`
(`true`), `samesite` (`'Strict'`).

La cola se indexa por `nombre|dominio|path`, no solo por nombre. Eso permite emitir
**dos cookies con el mismo nombre y distinto dominio en una sola respuesta** — por
ejemplo, borrar una variante *host-only* antigua y fijar la de `.midominio.com` a la vez.

`withHeaders()` copia la cola de cookies a propósito: un middleware que devuelve una
instancia nueva no debe tirar las cookies que encoló el controlador.

---

## ResponseFactory

`HexaLite\ResponseFactory` — `src/ResponseFactory.php`

Proxy fluido que devuelve `response()` cuando se llama sin argumentos. Métodos
explícitos en vez de `__call()`, para no pagar el sobrecoste de los métodos mágicos.

```php
json(mixed $data, int $status = 200): Response
withCookie(string $name, string $value, array $options = []): Response
withHeaders(array $headers): Response
```

---

## Body

`HexaLite\Http\Body` — `src/Http/Body.php`

Vista sobre el cuerpo de la petición. El Router la inyecta al declararla en la firma
del controlador, y solo entonces parsea el cuerpo (es perezoso).

```php
public function crear(Body $body) { $nombre = $body->nombre; }
```

| Método | Devuelve |
|---|---|
| `__get(string $key): mixed` | Acceso por propiedad; `null` si no existe. |
| `__isset(string $key): bool` | ¿Existe la clave? |
| `get(string $key, mixed $default = null): mixed` | Acceso con valor por defecto. |
| `only(array $keys): array` | Solo esas claves. |
| `except(array $keys): array` | Todas menos esas. |
| `all(): array` | El array completo. |
| `validated(array $requiredKeys): array` | Comprueba que esas claves existan y no estén vacías; devuelve solo ellas. |

`validated()` lanza [`ValidationException`](#validationexception) si falta alguna. Es
una comprobación de presencia, nada más — para reglas de verdad usa un
[DTO](dto-validacion.md#dtos) o `$request->validate()`.

---

## Params

`HexaLite\Http\Params` — `src/Http/Params.php`

Parámetros de ruta y de query string juntos. **Los de ruta tienen prioridad.**

```php
__construct(array $routeParams = [], array $queryParams = [])
```

| Método | Devuelve |
|---|---|
| `__get(string $key): mixed` / `__isset(string $key): bool` | Acceso por propiedad. |
| `get(string $key, mixed $default = null): mixed` | Ruta primero, luego query. |
| `route(string $key, mixed $default = null): mixed` | Solo parámetros de ruta. |
| `query(string $key, mixed $default = null): mixed` | Solo query string. |
| `allRoute(): array` / `allQuery(): array` / `all(): array` | Los tres conjuntos. |
| `int(string $key, int $default = 0): int` | Casteado a entero. |
| `string(string $key, string $default = ''): string` | Casteado a cadena. |
| `bool(string $key, bool $default = false): bool` | Vía `FILTER_VALIDATE_BOOLEAN`; si no se puede interpretar, el valor por defecto. |
| `float(string $key, float $default = 0.0): float` | Casteado a flotante. |

---

## Router

`HexaLite\Http\Router` — `src/Http/Router.php`

Descubre rutas leyendo los atributos PHP de los controladores, las compila y despacha
la petición por la tubería completa: middlewares globales → middlewares de ruta →
guards → controlador.

```php
__construct(
    array $controllers,
    Container $container,
    string $cacheFile,
    bool $isProduction,
    array $guardAttributes = []
)
```

| Parámetro | Para qué |
|---|---|
| `$controllers` | FQCN de las clases con rutas. |
| `$container` | Resuelve controladores, middlewares, guards y dependencias inyectadas. |
| `$cacheFile` | Dónde se cachean las rutas compiladas. |
| `$isProduction` | Si es `true`, lee del caché y lo escribe; si es `false`, re-escanea en cada petición. |
| `$guardAttributes` | Clases de atributo que el Router debe reconocer como guards (p. ej. `AuthServiceProvider::guardAttributes()`). |

### Configuración

| Método | Efecto |
|---|---|
| `setBaseUrl(string $baseUrl): void` | Prefijo que se recorta del path antes de buscar. `'/'` equivale a vacío. |
| `getBaseUrl(): string` | El prefijo actual. |
| `addGlobalMiddleware(string $middlewareClass, int $priority = 100): void` | Middleware que se ejecuta en **todas** las peticiones, incluidas 404 y preflight OPTIONS. |
| `static getInstance(): ?Router` | Última instancia construida. La usa el helper `url()`. |

```php
registerExceptionHandler(string $throwableClass, callable $handler): void
```

Mapea una excepción a una `Response` sin acoplar el core a la librería que la lanza.
Los manejadores se evalúan con `instanceof`, así que registrar una clase base cubre
sus subclases; devolver `null` desde el handler delega al siguiente o al 500 final.

```php
$router->registerExceptionHandler(
    \Firebase\JWT\ExpiredException::class,
    fn($e) => Response::json(['error' => 'Sesión expirada'], 401)
);
```

### Despacho

| Método | Efecto |
|---|---|
| `dispatch(Request $request): void` | Resuelve y **envía** la respuesta. Es `handle()` + `send()`. |
| `handle(Request $request): Response` | Resuelve y **devuelve** la respuesta sin enviarla. |

`handle()` es la puerta para tests funcionales de extremo a extremo y para incrustar
HexaLite en otro runtime (workers, colas) sin tocar la salida global de PHP.

### Inyección en los métodos del controlador

El Router lee la firma del método y construye cada argumento según su tipo:

| Tipo declarado | Se inyecta |
|---|---|
| `Request` | La petición. |
| `Body` | El cuerpo parseado (perezoso). |
| `Params` | Parámetros de ruta + query. |
| Subclase de `Dtos` | El DTO hidratado **y validado**; si falla, 422. |
| `int`, `float`, `bool`, `string` | El parámetro de ruta del mismo nombre, casteado. |
| Cualquier otra clase | Resuelto desde el contenedor. |

### Errores que traduce de fábrica

| Excepción | Respuesta |
|---|---|
| [`ValidationException`](#validationexception) | `422` con `details` campo por campo. |
| [`HttpException`](#httpexception) | Su propio `status`, con `code` y `details`. Solo se registran las 5xx — las 4xx son control de flujo deliberado y llenarían el log de comportamiento normal. |
| Cualquier otra | `500`. En producción el mensaje interno **no** se expone (podría filtrar errores SQL o rutas del sistema); en desarrollo sí. |

La traducción ocurre **dentro** de la cadena de middlewares, para que un error salga
igualmente con sus cabeceras CORS.

### Rendimiento

Las rutas estáticas van a una tabla asociativa de búsqueda O(1). Las dinámicas se
compilan en un **mega-regex** con marcas `(*MARK:n)` (patrón FastRoute), así que
también se resuelven en una sola pasada. Las partes literales del path se escapan,
de modo que `/v1.0/{id}` no casa con `/vX0/5`.

En producción el resultado se serializa a un array PHP nativo que se carga con
`require` — lo sirve OPcache, con cero E/S. La serialización usa
[`PhpExporter`](contenedor.md#phpexporter), que lo escribe **en una sola línea**: un
archivo bastante más pequeño y de carga más rápida que el de `var_export()`.

### Introspección

| Método | Devuelve |
|---|---|
| `getRoutes(): array` | Tabla interna cruda. |
| `getRoutesInfo(): array` | Rutas normalizadas: método, path, controlador, middlewares, guards. |
| `printRoutes(): void` | Las imprime coloreadas por consola. |

---

## HttpException

`HexaLite\Http\HttpException` — `src/Http/HttpException.php`

Excepción HTTP ligera: código de estado, código de error legible por la interfaz y
detalles opcionales.

```php
__construct(
    string $errorCode,
    string $message = '',
    int $status = 400,
    ?array $details = null
)

getStatusCode(): int      // el status
getErrorCode(): string    // el código legible
getDetails(): ?array      // los detalles
```

```php
throw new HttpException('CREDENCIALES_INVALIDAS', 'Correo o contraseña incorrectos', 401);
```

El `$errorCode` es lo que el front debe usar para decidir qué mostrar: el `$message`
es texto para humanos y puede cambiar sin previo aviso.

---

## ValidationException

`HexaLite\Http\ValidationException` — `src/Http/ValidationException.php`

```php
__construct(public array $errors)
```

`$errors` va indexado por campo, con una lista de mensajes por cada uno. El Router lo
traduce a un `422` con la forma `{"error": "Validation Failed", "details": {...}}`.

---

## MiddlewareInterface

`HexaLite\Http\Domain\MiddlewareInterface` — `src/Http/Domain/MiddlewareInterface.php`

```php
handle(Request $request, callable $next): Response
```

Recibe la petición y el resto de la tubería. Llama a `$next($request)` para continuar,
o devuelve una `Response` propia para cortocircuitar.

```php
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $id = bin2hex(random_bytes(8));
        $request->setAttribute('request_id', $id);

        return $next($request)->withHeaders(['X-Request-Id' => $id]);
    }
}
```

---

## GuardInterface

`HexaLite\Http\Domain\GuardInterface` — `src/Http/Domain/GuardInterface.php`

```php
canActivate(Request $request): bool|Response
```

`true` deja pasar. `false` corta con el rechazo por defecto. Devolver una `Response`
permite controlar exactamente el cuerpo y las cabeceras del rechazo — es lo que hace
el [`ThrottleGuard`](auth.md#throttleguard) para añadir las cabeceras `X-RateLimit-*`.

Los guards corren **después** de los middlewares y **antes** del controlador, ordenados
por la prioridad que declare su atributo (menor = antes).

---

## CorsMiddleware

`HexaLite\Http\Middlewares\CorsMiddleware` — `src/Http/Middlewares/CorsMiddleware.php`

CORS con credenciales seguras por defecto.

```php
__construct(
    array $allowedOrigins   = ['*'],
    array $allowedMethods   = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    array $allowedHeaders   = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
    bool  $allowCredentials = false,
    int   $maxAge           = 86400,
)

static fromEnv(): CorsMiddleware
handle(Request $request, callable $next): Response
```

**La regla que más se rompe en la práctica:** con `Access-Control-Allow-Credentials:
true` nunca se puede reflejar un `Origin` arbitrario ni usar `*`. Si se hace,
cualquier web puede leer respuestas autenticadas con la sesión de la víctima. Aquí,
con credenciales activadas, solo se responde el `Origin` si está **explícitamente** en
la lista.

`fromEnv()` lee `FRONTEND_ORIGINS` (lista separada por comas) y `CORS_CREDENTIALS`
(`'0'` para desactivar). Sin allowlist se degrada a modo abierto **sin** cookies, que
es lo único seguro que se puede hacer sin más información.

Las peticiones `OPTIONS` se responden con un `204` de inmediato, sin llegar a la app.

---

## Atributos de ruta

`HexaLite\Attributes\*` — `src/Attributes/`

### Route

```php
#[Attribute(TARGET_METHOD)]
__construct(public string $path, public string $method = 'GET')
```

Los segmentos dinámicos se escriben `{nombre}` y llegan al método por su nombre:
`#[Route('/users/{id}', 'GET')]` con `public function ver(int $id)`.

### Controller

```php
#[Attribute(TARGET_CLASS)]
__construct(public string $basePath = '')
```

Prefijo común de todas las rutas de la clase.

### Middleware

```php
#[Attribute(TARGET_CLASS | TARGET_METHOD | IS_REPEATABLE)]
__construct(public string $middlewareClass, public int $priority = 100)
```

Repetible. Los de clase y los de método se juntan y se ordenan por prioridad
(menor = antes).

### TimeAlwaysExecuted

```php
#[Attribute(TARGET_METHOD | TARGET_CLASS)]
__construct(public float $seconds = 2.0)
```

Fija un tiempo mínimo de respuesta, rellenando con espera lo que falte. Sirve contra
ataques de temporización: que un login tarde lo mismo exista o no la cuenta.

**Tope de 5 segundos**, aplicado por el Router. El relleno bloquea el worker de
PHP-FPM, así que una mala configuración no puede retenerlo indefinidamente; la
defensa principal sigue siendo el rate limit.

---

## Helpers globales

`src/helpers.php`, cargado por el autoload `files` de Composer. Todos están protegidos
con `function_exists()`, así que tu aplicación puede redefinirlos.

### `response()`

```php
response(mixed $data = null, int $status = 200): Response|ResponseFactory
```

Sin argumentos (o con `null`) devuelve un [`ResponseFactory`](#responsefactory) para
encadenar; con datos, un `Response` JSON.

```php
return response(['ok' => true]);
return response($data, 201);
return response()->withCookie('t', $v);
```

### `env()`

```php
env(string $key, mixed $default = null): mixed
```

Lee del entorno con casteo de literales: `"true"`/`"false"` → booleano, `"null"` →
`null`, `"empty"` → `''`. Acepta también las formas entre paréntesis (`"(true)"`).

### `loadEnv()`

```php
loadEnv(string $filePath): void
```

Parser mínimo de `.env`, sin dependencias. Ignora comentarios y líneas sin `=`, y
quita comillas envolventes. Si el fichero no existe, no hace nada.

### `url()`

```php
url(string $path): string
```

URL absoluta para un path de la aplicación, **inmune a la inyección por cabecera
`Host`**. Resuelve en este orden:

1. `APP_URL`, si está configurada — la base canónica, y la que debes usar para
   enlaces que van por correo.
2. El host de la petición, sanitizado y restringido a `APP_TRUSTED_HOSTS` si esa
   lista existe.
3. Si nada es fiable, una ruta relativa.

### `console_log()`

```php
console_log(string $message): void
```

Escribe en el log de errores de PHP. Existe para depurar sin ensuciar la respuesta:
en una API JSON un `echo` rompe el cuerpo, esto no. Para el log de la aplicación usa
el logger PSR-3.

### `reportError()`

```php
reportError(?LoggerInterface $logger, mixed $e): void
```

Registra una excepción con el contexto ya extraído: de una `ValidationException` saca
el detalle campo por campo, del resto el mensaje y el `fichero:línea`. Usa la **clase**
de la excepción como texto del log, para que agrupe por tipo de fallo en vez de por
mensajes irrepetibles. Sin logger cae al log de PHP.
