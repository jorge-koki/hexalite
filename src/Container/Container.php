<?php

namespace HexaLite\Container;

use ReflectionClass;
use ReflectionNamedType;
use InvalidArgumentException;
use HexaLite\Attributes\Inject;

class Container
{
    // OJO (singleton por defecto): toda clase resuelta se cachea en $instances y se
    // reutiliza durante la vida del contenedor. En PHP-FPM (un proceso por request)
    // esto es correcto y eficiente. Bajo un runtime daemon (Amp), donde el contenedor
    // vive entre requests, un servicio con estado compartiría datos entre peticiones
    // o tenants: para esos casos regístralo como transient() (instancia fresca por get()).
    private array $instances  = [];
    private array $bindings   = [];
    private array $factories  = [];
    private array $transients = [];

    // Detecta dependencias circulares durante la resolución. Set implementado como
    // array asociativo <id => true>: contains() → isset(), add() → asignación,
    // remove() → unset(). Evita depender de la extensión PECL `ext-ds`.
    private array $resolving = [];

    // constructor metadata cache (performance)
    private array $constructorCache = [];

    // deferred cache write flag (write once at shutdown, not per-class)
    private bool $dirty = false;

    public function __construct(
        private ?string $cacheFile = null,
        private bool $isProduction = false
    ) {
        if ($this->isProduction && $this->cacheFile) {
            $this->loadCache();
        }
    }

    /**
     * Register a concrete instance (singleton)
     */
    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Register a lazy singleton factory. It will receive the container.
     */
    public function setFactory(string $id, callable $factory): void
    {
        if (!is_callable($factory)) {
            throw new InvalidArgumentException("La fábrica debe ser una función o cierre válido");
        }
        $this->factories[$id] = $factory;
    }

    /**
     * Registra una fábrica TRANSIENT: devuelve una instancia NUEVA en cada get(),
     * sin cachear. Útil para servicios con estado por-request (sobre todo bajo un
     * runtime daemon como Amp, donde el contenedor vive entre peticiones).
     */
    public function transient(string $id, callable $factory): void
    {
        if (!is_callable($factory)) {
            throw new InvalidArgumentException("La fábrica transient debe ser una función o cierre válido");
        }
        $this->transients[$id] = $factory;
    }

    /**
     * Bind an interface (or alias) to a concrete implementation class.
     */
    public function bind(string $interface, string $implementation): void
    {
        if (!class_exists($implementation)) {
            throw new NotFoundException("La clase de implementación '$implementation' no existe");
        }
        // optional: check that $implementation implements or extends $interface
        $this->bindings[$interface] = $implementation;
    }

    /**
     * Checks if an id can be resolved (instance, factory, binding or class exists)
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->factories[$id])
            || isset($this->transients[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    /**
     * Resolve a dependency with autowiring.
     *
     * @throws ContainerException
     * @throws NotFoundException
     * @throws CircularDependencyException
     */
    public function get(string $id): mixed
    {
        // follow binding if it exists
        if (isset($this->bindings[$id])) {
            $id = $this->bindings[$id];
        }

        // transient registrado → instancia NUEVA en cada get(), sin cachear
        if (isset($this->transients[$id])) {
            return ($this->transients[$id])($this);
        }

        // already built singleton?
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // factory registered?
        if (isset($this->factories[$id])) {
            return $this->resolveFactory($id);
        }

        // circular detection
        if (isset($this->resolving[$id])) {
            throw new CircularDependencyException("Dependencia circular detectada al resolver: $id");
        }

        if (!class_exists($id)) {
            throw new NotFoundException("Clase '$id' no encontrada en el contenedor");
        }

        return $this->resolveClass($id);
    }

    /**
     * Internal helper for factories (also checks circular deps)
     */
    private function resolveFactory(string $id): mixed
    {
        if (isset($this->resolving[$id])) {
            throw new CircularDependencyException("Dependencia circular detectada en fábrica: $id");
        }

        $this->resolving[$id] = true;
        try {
            $factory = $this->factories[$id];
            $instance = $factory($this);
            $this->instances[$id] = $instance;
            return $instance;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Internal helper for constructing a class via autowiring.
     */
    private function resolveClass(string $id): mixed
    {
        if (!isset($this->constructorCache[$id])) {
            $this->cacheConstructorMetadata($id);
            $this->dirty = true;
        }

        $metadata = $this->constructorCache[$id];

        if (!$metadata['hasConstructor']) {
            $instance = new $id();
            $this->instances[$id] = $instance;
            return $instance;
        }

        // Marcar la clase como "en resolución" para que el guard de get() detecte
        // dependencias circulares (A → B → A) en lugar de caer en recursión infinita
        // (que terminaría en un fatal por agotamiento de memoria/pila).
        $this->resolving[$id] = true;
        try {
            $dependencies = [];
            foreach ($metadata['params'] as $param) {
                if ($param['isBuiltin']) {
                    $dependencies[] = $param['defaultValue'];
                } else {
                    $resolveId = $param['injectAlias'] ?? $param['typeName'];
                    if ($param['isNullable'] && !$this->has($resolveId)) {
                        $dependencies[] = null;
                    } else {
                        try {
                            $dependencies[] = $this->get($resolveId);
                        } catch (ContainerException $e) {
                            // Propagar tal cual (incluida CircularDependencyException)
                            // sin re-envolver, para no perder el tipo de excepción.
                            throw $e;
                        } catch (\Throwable $e) {
                            throw new ContainerException("Error resolviendo dependencia '$resolveId' para '$id': " . $e->getMessage(), 0, $e);
                        }
                    }
                }
            }

            try {
                $instance = new $id(...$dependencies);
                $this->instances[$id] = $instance;
                return $instance;
            } catch (\Throwable $e) {
                throw new ContainerException("Error al instanciar clase '$id': " . $e->getMessage(), 0, $e);
            }
        } finally {
            // Liberar el marcador siempre (éxito o error) para no bloquear
            // resoluciones legítimas futuras de la misma clase.
            unset($this->resolving[$id]);
        }
    }

    /**
     * Analyse and cache the constructor signature of a class.
     */
    private function cacheConstructorMetadata(string $id): void
    {
        try {
            $reflector = new ReflectionClass($id);

            if (!$reflector->isInstantiable()) {
                throw new ContainerException("La clase '$id' no es instanciable");
            }

            $constructor = $reflector->getConstructor();
            if (!$constructor) {
                $this->constructorCache[$id] = [
                    'hasConstructor' => false,
                    'params' => []
                ];
                return;
            }

            $params = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type instanceof ReflectionNamedType) {
                    throw new ContainerException("Parámetro sin tipo nombrado en constructor de '$id': " . $param->getName());
                }

                $typeName = $type->getName();
                $isBuiltin = $type->isBuiltin();
                $isNullable = $type->allowsNull();

                if ($isBuiltin) {
                    if (!$param->isDefaultValueAvailable()) {
                        throw new ContainerException("Parámetro primitivo '$typeName' sin valor por defecto en constructor de '$id'");
                    }
                    $params[] = [
                        'isBuiltin' => true,
                        'typeName' => $typeName,
                        'defaultValue' => $param->getDefaultValue(),
                        'injectAlias' => null,
                        'isNullable' => $isNullable
                    ];
                } else {
                    $injectAlias = null;
                    $attributes = $param->getAttributes(Inject::class);
                    if (!empty($attributes)) {
                        $attr = $attributes[0]->newInstance();
                        $injectAlias = $attr->alias ?? null;
                    }
                    $params[] = [
                        'isBuiltin' => false,
                        'typeName' => $typeName,
                        'defaultValue' => null,
                        'injectAlias' => $injectAlias,
                        'isNullable' => $isNullable
                    ];
                }
            }

            $this->constructorCache[$id] = [
                'hasConstructor' => true,
                'params' => $params
            ];
        } catch (\Exception $e) {
            throw new ContainerException("Error al analizar constructor de '$id': " . $e->getMessage(), 0, $e);
        }
    }

    private function loadCache(): void
    {
        if (empty($this->cacheFile) || !file_exists($this->cacheFile)) {
            return;
        }
        try {
            // Se usa require directo para aprovechar el motor OPcache de PHP (0 I/O)
            $loaded = require $this->cacheFile;
            if (is_array($loaded)) {
                $this->constructorCache = $loaded['constructors'] ?? [];
                $this->bindings         = $loaded['bindings'] ?? [];
            } else {
                throw new \RuntimeException("El contenido del caché no es un array válido");
            }
        } catch (\Throwable $e) {
            error_log("Error al cargar el caché del Container (OPcache): " . $e->getMessage());
        }
    }

    private function saveCache(): void
    {
        $dir = dirname($this->cacheFile);
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            // Exportar un array PHP nativo
            $exportData = [
                'constructors' => $this->constructorCache,
                'bindings'     => $this->bindings,
            ];

            $content = "<?php\n\nreturn " . var_export($exportData, true) . ";\n";

            // Escritura ATÓMICA: tmp único + rename (atómico en el mismo filesystem).
            // Evita que otro worker FPM haga `require` de un caché a medio escribir
            // (fatal parse error). El Container puede guardar desde __destruct en
            // varios workers a la vez, así que esto es necesario para no corromperlo.
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
        } catch (\Throwable $e) {
            error_log("Error al guardar el caché del Container (OPcache): " . $e->getMessage());
        }
    }

    public function __destruct()
    {
        if ($this->dirty && $this->isProduction && $this->cacheFile) {
            $this->saveCache();
        }
    }
}
