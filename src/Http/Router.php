<?php
declare(strict_types=1);

namespace HexaLite\Http;

use ReflectionClass;
use ReflectionNamedType;
use HexaLite\Attributes\Route;
use HexaLite\Attributes\Middleware;
use HexaLite\Attributes\Controller;
use HexaLite\Container\Container;
use HexaLite\Http\Domain\GuardInterface;
use HexaLite\Attributes\TimeAlwaysExecuted;
use HexaLite\Http\DTO\Dtos;
use Throwable;

class Router
{
    /**
     * Tope de seguridad (segundos) para el pad de #[TimeAlwaysExecuted]. El pad
     * bloquea el worker FPM, así que limitamos cuánto puede retenerlo una mala
     * configuración o un abuso. La defensa principal sigue siendo el rate-limit.
     */
    private const MAX_TIME_PAD_SECONDS = 5.0;

    private array          $routes            = [];
    private array          $globalMiddlewares = [];
    private string         $baseUrl           = '';
    private static ?Router $instance          = null;

    /**
     * Manejadores de excepciones registrados por la aplicación. Permiten mapear
     * excepciones de librerías de terceros (p. ej. las de un paquete JWT) a una
     * Response HTTP sin que el core del framework dependa de esas librerías.
     *
     * @var array<class-string<Throwable>, callable(Throwable): (Response|null)>
     */
    private array $exceptionHandlers = [];

    public function __construct(
        private array $controllers,
        private Container $container,
        private string $cacheFile,
        private bool $isProduction,
        private array $guardAttributes = []
    ) {
        $this->loadRoutes();
        self::$instance = $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIGURACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = '/' . trim($baseUrl, '/');
        if ($this->baseUrl === '/') {
            $this->baseUrl = '';
        }
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public static function getInstance(): ?Router
    {
        return self::$instance;
    }

    public function addGlobalMiddleware(string $middlewareClass, int $priority = 100): void
    {
        $this->globalMiddlewares[] = ['class' => $middlewareClass, 'priority' => $priority];
    }

    /**
     * Registra un manejador para convertir una excepción concreta en una Response.
     *
     * El core solo conoce, de fábrica, ValidationException y HttpException. Usa
     * esto para mapear excepciones de librerías externas (p. ej. "token expirado"
     * de un paquete JWT → 401) SIN acoplar el framework a esas dependencias. Los
     * manejadores se evalúan con `instanceof`, así que registrar una clase base
     * cubre también a sus subclases. Devuelve `null` desde el handler para delegar
     * al siguiente manejador o al fallback 500.
     *
     * @param class-string<Throwable>                    $throwableClass Clase (o base) a interceptar.
     * @param callable(Throwable): (Response|null)       $handler        Construye la Response.
     */
    public function registerExceptionHandler(string $throwableClass, callable $handler): void
    {
        $this->exceptionHandlers[$throwableClass] = $handler;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CARGA DE RUTAS
    // ─────────────────────────────────────────────────────────────────────────

    private function loadRoutes(): void
    {
        if ($this->isProduction && file_exists($this->cacheFile)) {
            try {
                // Se usa require directo para aprovechar OPcache (0 I/O en requests siguientes)
                $cached = require $this->cacheFile;
                if (\is_array($cached)) {
                    // Validar forma esperada (buckets 'static'|'dynamic') — si no coincide,
                    // ignoramos el cache viejo para forzar re-scan y evitar incompatibilidades.
                    $valid = true;
                    foreach ($cached as $method => $buckets) {
                        if (!\is_array($buckets) || (!isset($buckets['static']) && !isset($buckets['dynamic']))) {
                            $valid = false;
                            break;
                        }
                    }

                    if ($valid) {
                        $this->routes = $cached;
                        return;
                    }
                }
                @unlink($this->cacheFile);
            } catch (Throwable $e) {
                // Ignore read errors and regenerate cache
                error_log("Aviso: El caché de rutas existe pero no se pudo leer.");
            }
        }

        $this->scanRoutes();

        if ($this->isProduction) {
            $this->saveCache();
        }
    }

    private function scanRoutes(): void
    {
        foreach ($this->controllers as $controllerClass) {
            $reflection = new ReflectionClass($controllerClass);

            $basePath = '';
            $controllerAttributes = $reflection->getAttributes(Controller::class);
            if (!empty($controllerAttributes)) {
                $controller = $controllerAttributes[0]->newInstance();
                $basePath = rtrim($controller->basePath, '/');
            }

            $classMiddlewares = [];
            foreach ($reflection->getAttributes(Middleware::class) as $attr) {
                $mw = $attr->newInstance();
                $classMiddlewares[] = ['class' => $mw->middlewareClass, 'priority' => $mw->priority];
            }

            $classGuards = [];
            foreach ($this->guardAttributes as $attrClass) {
                foreach ($reflection->getAttributes($attrClass) as $attr) {
                    $instance = $attr->newInstance();
                    $params = [];
                    $guardReflection = new ReflectionClass($instance);
                    foreach ($guardReflection->getProperties() as $prop) {
                        $name = $prop->getName();
                        if ($name !== 'guardClass' && $name !== 'priority') {
                            $params[$name] = $instance->$name;
                        }
                    }
                    $classGuards[] = [
                        'class'    => $instance->guardClass,
                        'priority' => $instance->priority,
                        'params'   => $params,
                    ];
                }
            }

            $classTimeSeconds = null;
            $classTimeAttrs   = $reflection->getAttributes(TimeAlwaysExecuted::class);
            if (!empty($classTimeAttrs)) {
                $classTimeSeconds = $classTimeAttrs[0]->newInstance()->seconds;
            }

            foreach ($reflection->getMethods() as $method) {
                $routeAttributes = $method->getAttributes(Route::class);
                if (empty($routeAttributes)) {
                    continue;
                }

                $methodMiddlewares = [];
                foreach ($method->getAttributes(Middleware::class) as $attr) {
                    $mw = $attr->newInstance();
                    $methodMiddlewares[] = ['class' => $mw->middlewareClass, 'priority' => $mw->priority];
                }

                $methodGuards = [];
                foreach ($this->guardAttributes as $attrClass) {
                    foreach ($method->getAttributes($attrClass) as $attr) {
                        $instance = $attr->newInstance();
                        $params   = [];
                        $methodReflection = new ReflectionClass($instance);
                        foreach ($methodReflection->getProperties() as $prop) {
                            $name = $prop->getName();
                            if ($name !== 'guardClass' && $name !== 'priority') {
                                $params[$name] = $instance->$name;
                            }
                        }
                        $methodGuards[] = [
                            'class'    => $instance->guardClass,
                            'priority' => $instance->priority,
                            'params'   => $params,
                        ];
                    }
                }

                $guards = [...$classGuards, ...$methodGuards];
                usort($guards, fn($a, $b) => $a['priority'] <=> $b['priority']);

                $allMiddlewares = [...$classMiddlewares, ...$methodMiddlewares];
                usort($allMiddlewares, fn($a, $b) => $a['priority'] <=> $b['priority']);

                $methodTimeAttrs = $method->getAttributes(TimeAlwaysExecuted::class);
                $timeSeconds     = !empty($methodTimeAttrs)
                    ? $methodTimeAttrs[0]->newInstance()->seconds
                    : $classTimeSeconds;

                // ── Capturar la firma del método para inyección ──────────────
                $paramSignature = $this->resolveMethodSignature($method);

                foreach ($routeAttributes as $attribute) {
                    $route    = $attribute->newInstance();
                    $fullPath = $basePath . $route->path;

                    // ── Determinar si la ruta tiene parámetros dinámicos ─────
                    // /user/{id}/posts/{postId}  →  regex + param names
                    [$regex, $paramNames] = $this->compileRoute($fullPath);

                    $routeEntry = [
                        'controller'      => $controllerClass,
                        'method'          => $method->getName(),
                        'middlewares'     => $allMiddlewares,
                        'guards'          => $guards,
                        'time'            => $timeSeconds,
                        'param_names'     => $paramNames,
                        'param_signature' => $paramSignature,
                        // Solo se usa si hay parámetros dinámicos
                        'regex'           => $paramNames ? $regex : null,
                    ];

                    // Rutas estáticas → bucket O(1); dinámicas → bucket separado
                    if (empty($paramNames)) {
                        $this->routes[$route->method]['static'][$fullPath] = $routeEntry;
                    } else {
                        $this->routes[$route->method]['dynamic'][] = $routeEntry + ['path' => $fullPath];
                    }
                }
            }
        }

        // ── Compilación FastRoute Mega-Regex ─────────────────────────────────
        foreach ($this->routes as $httpMethod => $buckets) {
            $dynamic = $buckets['dynamic'] ?? [];
            if (empty($dynamic)) {
                continue;
            }

            $regexes = [];
            foreach ($dynamic as $index => $route) {
                // strip delimiters: '~^' (length 2) and '$~' (length 2)
                $innerRegex = substr($route['regex'], 2, -2);
                $regexes[] = $innerRegex . '(*MARK:' . $index . ')';
            }

            // Unimos todo en un solo regex usando OR (|). Sin el flag 'x': antes el
            // modo extendido hacía que un espacio o '#' en un path se ignoraran (y
            // obligaba a meter un espacio falso antes de (*MARK)). Ahora los literales
            // van escapados por compileRoute(), así que no hace falta.
            $combined = '~^(?:' . implode('|', $regexes) . ')$~';
            $this->routes[$httpMethod]['combined_regex'] = $combined;
        }
    }

    /**
     * Convierte /user/{id}/posts/{postId} en un regex y la lista de nombres
     * de parámetros en el orden que aparecen.
     *
     * @return array{0: string, 1: string[]}
     */
    private function compileRoute(string $path): array
    {
        // Extrae todos los {name} en orden
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        $paramNames = $matches[1];

        if (empty($paramNames)) {
            return ['', []];
        }

        // Construye el regex ESCAPANDO las partes literales del path (para que '.',
        // '+', etc. no se interpreten como metacaracteres ni permitan colisiones de
        // rutas como /v1.0/{id} casando /vX0/5) y sustituyendo cada {name} por un
        // grupo de captura que admite todo salvo '/'. Delimitador '~', igual que el
        // mega-regex combinado de scanRoutes.
        $regex = preg_replace_callback(
            '/\{(\w+)\}|[^{}]+/',
            fn($m) => isset($m[1]) ? '([^/]+)' : preg_quote($m[0], '~'),
            $path
        );

        return ['~^' . $regex . '$~', $paramNames];
    }

    /**
     * Analiza los parámetros del método PHP y devuelve la firma que el
     * despachador usará para saber qué inyectar en cada posición.
     *
     * Posibles tipos de cada ítem:
     *   ['kind' => 'request']                          → Request $request
     *   ['kind' => 'body']                             → Body $body
     *   ['kind' => 'params']                           → Params $params
     *   ['kind' => 'dto',   'class' => LoginDto::class] → LoginDto $dto  (subclase de Dtos)
     *   ['kind' => 'route_param', 'name' => 'id', 'type' => 'int']  → int $id
     *   ['kind' => 'container',  'class' => MyService::class]        → cualquier otra clase
     *
     * @return array<int, array{kind: string, name?: string, type?: string, class?: string}>
     */
    private function resolveMethodSignature(\ReflectionMethod $method): array
    {
        $signature = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                $signature[] = match (true) {
                    $typeName === Request::class || $typeName === 'Request' => ['kind' => 'request'],
                    $typeName === Body::class    || $typeName === 'Body'   => ['kind' => 'body'],
                    $typeName === Params::class  || $typeName === 'Params' => ['kind' => 'params'],
                    // DTO: cualquier subclase de Dtos → hidratación + validación automática
                    Dtos::isDtoClass($typeName) => ['kind' => 'dto', 'class' => $typeName],
                    // Cualquier otra clase → Container (DI)
                    default => ['kind' => 'container', 'class' => $typeName],
                };
            } else {
                // Tipo primitivo (int, string, float, bool) → parámetro de ruta
                $castType    = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
                $signature[] = [
                    'kind' => 'route_param',
                    'name' => $param->getName(),
                    'type' => $castType,
                ];
            }
        }

        return $signature;
    }

    private function saveCache(): void
    {
        $dir = dirname($this->cacheFile);
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            // Exportar como PHP nativo para aprovechar OPcache (0 I/O en loads siguientes)
            $content = "<?php\n\nreturn " . var_export($this->routes, true) . ";\n";

            // Escritura ATÓMICA: tmp único + rename. rename() es atómico en el mismo
            // filesystem, así que un worker concurrente que haga `require` del caché
            // verá siempre el archivo completo (viejo o nuevo), nunca uno a medio
            // escribir (que provocaría un fatal parse error en producción).
            $tmp = $this->cacheFile . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
            if (file_put_contents($tmp, $content, LOCK_EX) !== false) {
                if (@rename($tmp, $this->cacheFile)) {
                    if (\function_exists('opcache_invalidate')) {
                        @opcache_invalidate($this->cacheFile, true);
                    }
                } else {
                    @unlink($tmp);
                }
            }
        } catch (Throwable $e) {
            error_log("Error al guardar el caché del Router: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DESPACHO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve la petición y ENVÍA la respuesta. Es el punto de entrada normal
     * desde `public/index.php`.
     */
    public function dispatch(Request $request): void
    {
        $this->handle($request)->send();
    }

    /**
     * Resuelve la petición y devuelve la Response SIN enviarla.
     *
     * Es la misma tubería que usa `dispatch()` —middlewares globales, middlewares
     * de ruta, guards y controlador—, solo que devolviendo el objeto en vez
     * de escribirlo. Sirve para tests funcionales de extremo a extremo y para
     * incrustar HexaLite en otro runtime (workers, colas, tests de integración)
     * sin tocar la salida global de PHP.
     */
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = $request->getPath();

        // Remover base URL
        if ($this->baseUrl !== '' && str_starts_with($path, $this->baseUrl)) {
            $path = substr($path, \strlen($this->baseUrl));
            if ($path === '' || $path[0] !== '/') {
                $path = "/{$path}";
            }
        }

        // Preflight OPTIONS
        if ($method === 'OPTIONS') {
            $finalHandler = fn($request) => new Response('', 204);
            foreach (array_reverse($this->globalMiddlewares) as $mw) {
                $finalHandler = $this->wrapMiddleware($mw['class'], $finalHandler);
            }
            $response = $finalHandler($request);
            return $response instanceof Response ? $response : new Response('', 204);
        }

        // ── Buscar handler ────────────────────────────────────────────────────
        [$handler, $routeParams] = $this->findHandler($method, $path);

        if (!$handler) {
            $notFoundHandler = fn($req) => Response::json(['error' => 'Not Found'], 404);
            foreach (array_reverse($this->globalMiddlewares) as $mw) {
                $notFoundHandler = $this->wrapMiddleware($mw['class'], $notFoundHandler);
            }
            return $notFoundHandler($request);
        }

        $controllerClass = $handler['controller'];
        $methodName      = $handler['method'];
        $timeSeconds     = $handler['time'] ?? null;
        $start           = $timeSeconds !== null ? microtime(true) : null;

        $request->setAttribute('route_controller', $controllerClass);
        $request->setAttribute('route_method', $methodName);

        // Guardar los route params en el request (accesible para middlewares)
        if (!empty($routeParams)) {
            $request->setAttribute('_route_params', $routeParams);
        }

        $guards          = $handler['guards'] ?? [];
        $paramSignature  = $handler['param_signature'] ?? [];

        // ── Handler final: guards + controlador ──────────────────────────────
        $controllerHandler = function (Request $request) use (
            $controllerClass, $methodName, $guards, $paramSignature, $routeParams
        ) {
            // Ejecutar Guards
            foreach ($guards as $guardConfig) {
                if (!empty($guardConfig['params'])) {
                    foreach ($guardConfig['params'] as $key => $value) {
                        $request->setAttribute("_guard_{$key}", $value);
                    }
                }

                /** @var GuardInterface $guard */
                $guard = $this->container->get($guardConfig['class']);

                $verdict = $guard->canActivate($request);

                // Un Guard puede devolver su PROPIA Response para explicar el
                // rechazo (401 sin sesión, 403 sin permiso…). Se respeta tal cual;
                // el 429 de abajo es solo el veredicto genérico de un `false`.
                if ($verdict instanceof Response) {
                    return $verdict;
                }

                if (!$verdict) {
                    $headers   = [];
                    $count     = $request->getAttribute('_throttle_count');
                    $limit     = $request->getAttribute('_throttle_limit');
                    $ttl       = $request->getAttribute('_throttle_ttl');

                    if ($limit !== null)     $headers['X-RateLimit-Limit']     = (string) $limit;
                    if ($count !== null) {
                        $headers['X-RateLimit-Count']     = (string) $count;
                        $remaining = $request->getAttribute('_throttle_remaining');
                        $headers['X-RateLimit-Remaining'] = (string) ($remaining ?? 0);
                    }
                    if ($ttl !== null)       $headers['Retry-After']           = (string) $ttl;

                    $response = Response::json([
                        'error'   => 'Too Many Requests',
                        'message' => 'Has excedido el límite de solicitudes permitidas'
                    ], 429);

                    return !empty($headers) ? $response->withHeaders($headers) : $response;
                }
            }

            // ── Resolver argumentos según firma ──────────────────────────────
            $args = $this->buildArguments($paramSignature, $request, $routeParams);

            $controller = $this->container->get($controllerClass);
            return $controller->$methodName(...$args);
        };

        // ── Cadena de middlewares ─────────────────────────────────────────────
        $finalHandler = $controllerHandler;

        foreach (array_reverse($handler['middlewares'] ?? []) as $mw) {
            $params       = $mw['params'] ?? [];
            $finalHandler = $this->wrapMiddleware($mw['class'], $finalHandler, $params);
        }

        // Convertir excepciones en Response AQUÍ (DENTRO de la cadena): así los
        // errores (401 token expirado, 422 validación, 500) regresan a través de
        // los middlewares globales y salen CON cabeceras CORS. Antes se construían
        // fuera de la cadena → cross-origin el navegador los bloqueaba ("Failed to
        // fetch") y el frontend nunca veía el status real (rompía el refresh).
        $innerHandler = $finalHandler;
        $finalHandler = function (Request $req) use ($innerHandler): Response {
            try {
                return $innerHandler($req);
            } catch (Throwable $e) {
                return $this->throwableToResponse($e);
            }
        };

        foreach (array_reverse($this->globalMiddlewares) as $mw) {
            $finalHandler = $this->wrapMiddleware($mw['class'], $finalHandler);
        }

        $response = null;
        try {
            $response = $finalHandler($request);
        } catch (Throwable $e) {
            // Red de seguridad: excepciones lanzadas por los propios middlewares
            // globales (el caso común ya se convirtió dentro de la cadena).
            $response = $this->throwableToResponse($e);
        }

        if ($timeSeconds !== null) {
            // Pad de tiempo constante (#[TimeAlwaysExecuted]) para mitigar timing
            // attacks (p.ej. enumeración de usuarios en login). usleep() bloquea el
            // worker FPM durante la espera, por eso acotamos el objetivo a un máximo
            // de seguridad: una mala config o un abuso no puede inmovilizar el worker
            // más de MAX_TIME_PAD_SECONDS.
            $target    = min((float) $timeSeconds, self::MAX_TIME_PAD_SECONDS);
            $elapsed   = microtime(true) - $start;
            $remaining = $target - $elapsed;
            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        return $response instanceof Response
            ? $response
            : Response::json(['error' => 'Internal Server Error'], 500);
    }

    /**
     * Convierte cualquier excepción en la Response JSON de error correspondiente.
     * Se usa DENTRO de la cadena de middlewares (para que el error salga con CORS)
     * y como red de seguridad en el catch externo de dispatch().
     */
    private function throwableToResponse(Throwable $e): Response
    {
        if ($e instanceof ValidationException) {
            return Response::json([
                'error'   => 'Validation Failed',
                'message' => 'Los datos enviados no son válidos.',
                'details' => $e->errors,
            ], 422);
        }

        // Las HttpException son control de flujo DELIBERADO: "credenciales
        // incorrectas", "enlace ya usado", "sin permiso". Registrarlas como error
        // llena el log de comportamiento normal de los usuarios y entierra los
        // fallos de verdad. Solo se registran las 5xx, que sí son bugs nuestros.
        if ($e instanceof \HexaLite\Http\HttpException) {
            if ($e->getStatusCode() >= 500) {
                error_log('[Router] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }

            return Response::json([
                'error'   => 'Request Failed',
                'message' => $e->getMessage(),
                'code'    => $e->getErrorCode(),
                'details' => $e->getDetails(),
            ], $e->getStatusCode());
        }

        error_log('[Router] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        // Manejadores registrados por la aplicación (ver registerExceptionHandler()).
        // Permiten mapear excepciones de librerías de terceros —p. ej. las de un
        // paquete JWT (token expirado/ inválido → 401)— a una Response sin acoplar
        // el core a esas dependencias. Se evalúan por instanceof, así una clase base
        // cubre a sus subclases.
        foreach ($this->exceptionHandlers as $throwableClass => $handler) {
            if ($e instanceof $throwableClass) {
                $mapped = $handler($e);
                if ($mapped instanceof Response) {
                    return $mapped;
                }
            }
        }

        // En producción NO exponemos el mensaje interno al cliente: puede
        // filtrar errores SQL (tablas/columnas/esquema), rutas del sistema u
        // otros detalles. El detalle completo ya quedó registrado en error_log
        // (ver arriba). En desarrollo sí se muestra para facilitar el debug.
        $isProd = getenv('APP_ENV') === 'production';
        return Response::json([
            'error'   => 'Internal Server Error',
            'message' => $isProd
                ? 'Ocurrió un error interno. Intenta de nuevo más tarde.'
                : $e->getMessage(),
        ], 500);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BÚSQUEDA DE HANDLER CON PARÁMETROS DINÁMICOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Busca el handler para (método HTTP, path).
     * Primero intenta la tabla estática O(1); si no encuentra, evalúa el mega-regex O(1) de rutas dinámicas.
     *
     * @return array{0: array|null, 1: array}  [$handler, $routeParams]
     */
    private function findHandler(string $method, string $path): array
    {
        // 1. Búsqueda estática O(1)
        $static = $this->routes[$method]['static'][$path] ?? null;
        if ($static) {
            return [$static, []];
        }

        // 2. Búsqueda dinámica ultra-rápida vía Super-Regex (FastRoute pattern)
        if (isset($this->routes[$method]['combined_regex'])) {
            $regex = $this->routes[$method]['combined_regex'];

            // Usamos PREG_UNMATCHED_AS_NULL para no desfasarnos con arrays si hay slots vacíos
            if (preg_match($regex, $path, $matches, PREG_UNMATCHED_AS_NULL)) {
                // PCRE inserta 'MARK' gracias al tag (*MARK:x)
                $mark = $matches['MARK'] ?? null;
                if ($mark !== null) {
                    $route = $this->routes[$method]['dynamic'][$mark] ?? null;
                    if ($route) {
                        // Limpiar offsets vacíos y el propio path original y el MARK
                        $capturedValues = [];
                        foreach ($matches as $k => $v) {
                            if (is_int($k) && $k > 0 && $v !== null) {
                                $capturedValues[] = $v;
                            }
                        }

                        // Vincular nombres reales a los valores capturados (evita offsets desfazados del regex global)
                        $routeParams = [];
                        foreach ($route['param_names'] as $i => $pname) {
                            $routeParams[$pname] = $capturedValues[$i] ?? null;
                        }

                        return [$route, $routeParams];
                    }
                }
            }
        }

        return [null, []];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INYECCIÓN DE ARGUMENTOS — El corazón del sistema Nest-style
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye la lista de argumentos que se pasarán al método del controlador,
     * respetando el orden y los tipos declarados en la firma PHP.
     *
     * Soporta (en cualquier orden y combinación):
     *
     *   Request $request          → el objeto Request completo
     *   Body $body                → cuerpo parseado (JSON / form-data)
     *   Params $params            → route params + query string combinados
     *   LoginDto $dto             → DTO validado automáticamente (subclase de Dtos)
     *   int $id                   → parámetro de ruta casteado a int
     *   string $slug              → parámetro de ruta como string
     *   MyService $svc            → resuelto desde el Container (DI)
     */
    private function buildArguments(array $signature, Request $request, array $routeParams): array
    {
        $args = [];

        // Body parseado (lazy: solo si alguien lo pide — Body o DTO)
        $bodyData = null;

        foreach ($signature as $paramDef) {
            $args[] = match ($paramDef['kind']) {

                'request' => $request,

                'body' => (function () use ($request, &$bodyData) {
                    if ($bodyData === null) {
                        $bodyData = $this->parseBody($request);
                    }
                    return new Body($bodyData);
                })(),

                'params' => new Params(
                    routeParams: $routeParams,
                    queryParams: $request->getQuery()
                ),

                // ── DTO: hidratación + validación automática ─────────────────
                // Parsea el body (igual que Body), pero además lo pasa por
                // las reglas del DTO. Si falla → ValidationException → 422.
                'dto' => (function () use ($request, &$bodyData, $paramDef) {
                    if ($bodyData === null) {
                        $bodyData = $this->parseBody($request);
                    }
                    /** @var class-string<Dtos> $dtoClass */
                    $dtoClass = $paramDef['class'];
                    // fromArray valida y castea; lanza ValidationException si hay errores
                    return $dtoClass::fromArray($bodyData);
                })(),

                'route_param' => $this->castRouteParam(
                    $routeParams[$paramDef['name']] ?? null,
                    $paramDef['type']
                ),

                'container' => $this->container->get($paramDef['class']),

                default => null,
            };
        }

        return $args;
    }

    /**
     * Parsea el cuerpo de la petición según Content-Type.
     * Soporta JSON, form-urlencoded y multipart/form-data.
     */
    private function parseBody(Request $request): array
    {
        // El objeto Request ya contiene el body parseado (JSON o $_POST)
        return $request->getBody();
    }

    /**
     * Castea un valor string (de la URL) al tipo PHP declarado en la firma.
     */
    private function castRouteParam(mixed $value, string $type): mixed
    {
        if ($value === null) return null;

        return match ($type) {
            'int'    => (int)   $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default  => (string) $value,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MIDDLEWARES
    // ─────────────────────────────────────────────────────────────────────────

    private function wrapMiddleware(string $middlewareClass, callable $next, array $params = []): callable
    {
        return function ($request) use ($middlewareClass, $next, $params) {
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $request->setAttribute("_mw_{$key}", $value);
                }
            }
            $middleware = $this->container->get($middlewareClass);
            return $middleware->handle($request, $next);
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTROSPECCIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function getRoutesInfo(): array
    {
        $info = [];

        foreach ($this->routes as $method => $buckets) {
            // Estáticas
            foreach ($buckets['static'] ?? [] as $path => $handler) {
                $info[] = $this->formatRouteInfo($method, $path, $handler);
            }
            // Dinámicas
            foreach ($buckets['dynamic'] ?? [] as $handler) {
                $info[] = $this->formatRouteInfo($method, $handler['path'], $handler);
            }
        }

        return $info;
    }

    private function formatRouteInfo(string $method, string $path, array $handler): array
    {
        return [
            'method'      => $method,
            'path'        => $path,
            'controller'  => $handler['controller'],
            'action'      => $handler['method'],
            'middlewares' => array_map(
                fn($mw) => ['class' => $mw['class'], 'priority' => $mw['priority']],
                $handler['middlewares'] ?? []
            ),
        ];
    }

    public function printRoutes(): void
    {
        if (php_sapi_name() !== 'cli-server') {
            return;
        }

        $lockFile   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hexalite_server.pid';
        $currentPid = getmypid();

        if (file_exists($lockFile) && file_get_contents($lockFile) == $currentPid) {
            return;
        }

        $routes = $this->getRoutesInfo();
        $output = "\n";
        $output .= "╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════╗\n";
        $output .= "║  HEXALITE FRAMEWORK - RUTAS REGISTRADAS                                                                      ║\n";
        $output .= "╠══════════════════════════════════════════════════════════════════════════════════════════════════════════════╣\n";

        foreach ($routes as $route) {
            $m           = str_pad($route['method'], 7);
            $p           = str_pad(substr($route['path'], 0, 38), 40);
            $controller  = basename(str_replace('\\', '/', $route['controller']));
            $action      = $route['action'];
            $color       = $this->getMethodColor($route['method']);
            $output     .= "║  {$color}{$m}\033[0m │ {$p} │ {$controller}@{$action}\n";
        }

        $output .= "╠══════════════════════════════════════════════════════════════════════════════════════════════════════════════╣\n";
        $output .= "║  Total: " . str_pad(count($routes) . " rutas registradas", 70) . "                    ║\n";
        $output .= "╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════╝\n\n";

        $stderr = fopen('php://stderr', 'w');
        fwrite($stderr, $output);
        fclose($stderr);

        file_put_contents($lockFile, $currentPid);
    }

    private function getMethodColor(string $method): string
    {
        return match ($method) {
            'GET'    => "\033[32m",
            'POST'   => "\033[33m",
            'PUT'    => "\033[34m",
            'DELETE' => "\033[31m",
            'PATCH'  => "\033[35m",
            default  => "\033[37m",
        };
    }
}
