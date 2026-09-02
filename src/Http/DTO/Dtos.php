<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO;

use HexaLite\Http\DTO\Attributes\ValidationAttribute;
use HexaLite\Http\Request;
use HexaLite\Http\ValidationException;
use HexaLite\Validation\RuleEngine;
use ReflectionClass;
use ReflectionProperty;

abstract class Dtos
{
    // ─────────────────────────────────────────────────────────────────────────
    // Cache estático de metadata Reflection (compartido entre todas las peticiones)
    // ─────────────────────────────────────────────────────────────────────────
    private static array $cachedMetadata = [];
    private static array $cachedRules    = [];
    private static array $cachedMessages = [];

    // ─────────────────────────────────────────────────────────────────────────
    // CONTRATO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reglas de validación del DTO.
     * Si la subclase no lo sobreescribe, se generan automáticamente desde los atributos PHP de cada propiedad.
     */
    public static function rules(): array
    {
        return static::rulesFromAttributes();
    }

    /**
     * Mensajes de error personalizados.
     * Estructura: ['campo' => ['nombreRegla' => 'mensaje', ...], ...]
     *
     * Si la subclase no lo sobreescribe, se generan automáticamente desde el argumento `message`
     * declarado en cada atributo de validación. Ej: #[Min(value: 3, message: 'Mínimo 3')].
     */
    public static function messages(): array
    {
        static::rulesFromAttributes(); // poblar caché de mensajes si aún no
        return self::$cachedMessages[static::class] ?? [];
    }

    /**
     * Mapeo opcional de campo → clase de valor.
     * La clase debe tener un método estático fromArray(array): static.
     *
     *   public static function casts(): array {
     *       return ['route_options' => RouteOptions::class];
     *   }
     */
    protected static function casts(): array
    {
        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FACTORIES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea el DTO desde un Request (valida via $request->validate()).
     * Usado cuando el controlador llama al DTO manualmente.
     *
     * @throws ValidationException  (lanzada por $request->validate())
     */
    public static function fromRequest(Request $request): static
    {
        $rules         = static::rules();
        $messages      = static::messages();
        $validatedData = $request->validate($rules, $messages);

        return static::hydrate($validatedData);
    }

    /**
     * Crea el DTO desde un array plano.
     * Usado por el Router para inyección automática: public function login(LoginDto $dto)
     *
     * @throws ValidationException  → el Router la captura y devuelve 422
     */
    final public static function fromArray(array $data): static
    {
        $validatedData = static::validateAndFilter($data);
        return static::hydrate($validatedData);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNALS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Valida $data contra las reglas del DTO y retorna solo los campos válidos.
     * Lanza ValidationException si hay errores.
     *
     * Reutiliza el mismo sistema de reglas que $request->validate() pero
     * sin necesitar un objeto Request — trabaja directamente con el array.
     *
     * @throws ValidationException
     */
    private static function validateAndFilter(array $data): array
    {
        $rules    = static::rules();
        $messages = static::messages();
        $errors   = [];

        // Separar reglas de primer nivel vs. reglas anidadas (notación de punto)
        $topRules    = array_filter($rules, fn($k) => !str_contains($k, '.'), ARRAY_FILTER_USE_KEY);
        $nestedRules = array_filter($rules, fn($k) =>  str_contains($k, '.'), ARRAY_FILTER_USE_KEY);

        // Campos enviados que no están definidos en el DTO (solo primer nivel)
        foreach (array_diff_key($data, $topRules) as $unknown => $_) {
            $errors[$unknown][] = "El campo '{$unknown}' no está permitido.";
        }

        // Validar campos de primer nivel (fail-fast por campo)
        foreach ($topRules as $field => $ruleDef) {
            $value = $data[$field] ?? null;
            $error = static::evaluateField($field, $value, $ruleDef, $data, $messages);
            if ($error !== null) {
                $errors[$field][] = $error;
            }
        }

        // Validar campos anidados con notación de punto (e.g. "route_options.routeType")
        foreach ($nestedRules as $dotField => $ruleDef) {
            $keys  = explode('.', $dotField);
            $value = $data;
            foreach ($keys as $key) {
                $value = \is_array($value) ? ($value[$key] ?? null) : null;
            }

            $error = static::evaluateField($dotField, $value, $ruleDef, $data, $messages);
            if ($error !== null) {
                $errors[$dotField][] = $error;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Retornar solo los campos de primer nivel validados
        return array_intersect_key($data, $topRules);
    }

    // Factory closures cacheadas por clase (evita recrear ReflectionClass en cada hydrate)
    private static array $factories = [];

    /**
     * Hidrata una instancia del DTO usando metadata cacheada.
     * Comparte la lógica entre fromRequest() y fromArray().
     */
    private static function hydrate(array $validatedData): static
    {
        $class = static::class;
        static::buildCache($class);

        if (!isset(self::$factories[$class])) {
            $ref = new ReflectionClass($class);
            self::$factories[$class] = fn() => $ref->newInstanceWithoutConstructor();
        }

        $dto = (self::$factories[$class])();

        // Castear valores antes de asignar
        $toAssign = [];
        foreach (self::$cachedMetadata[$class] as $name => $meta) {
            if (!\array_key_exists($name, $validatedData)) {
                if ($meta['nullable']) {
                    $toAssign[$name] = null;
                }
                continue;
            }

            $value = $validatedData[$name];

            if ($meta['isBuiltin'] && $value !== null) {
                $value = match ($meta['typeName']) {
                    'int'    => (int)    $value,
                    'float'  => (float)  $value,
                    'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    'string' => (string) $value,
                    default  => $value,
                };
            }

            // Aplicar cast a clase de valor si el DTO lo declara (solo si el array no está vacío)
            $castMap = static::casts();
            if (isset($castMap[$name]) && \is_array($value) && !empty($value)) {
                $value = $castMap[$name]::fromArray($value);
            }

            $toAssign[$name] = $value;
        }

        // Closure bound: asigna propiedades (incluidas readonly) sin setAccessible()
        (function () use ($toAssign): void {
            foreach ($toAssign as $prop => $val) {
                $this->$prop = $val;
            }
        })->bindTo($dto, $class)();

        return $dto;
    }

    /**
     * Genera las reglas (y los mensajes custom) leyendo los atributos PHP declarados en cada propiedad pública.
     * Resultado cacheado por clase — se invoca solo cuando la subclase no sobreescribe rules()/messages().
     */
    private static function rulesFromAttributes(): array
    {
        $class = static::class;

        if (isset(self::$cachedRules[$class])) {
            return self::$cachedRules[$class];
        }

        static::buildCache($class);

        $rules    = [];
        $messages = [];
        foreach (self::$cachedMetadata[$class] as $name => $meta) {
            /** @var ReflectionProperty $property */
            $property  = $meta['property'];
            $ruleParts = [];

            foreach ($property->getAttributes(ValidationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attrRef) {
                /** @var ValidationAttribute $attr */
                $attr        = $attrRef->newInstance();
                $ruleParts[] = $attr->toRuleString();

                $customMessage = $attr->getMessage();
                if ($customMessage !== null) {
                    $messages[$name][$attr->getRuleName()] = $customMessage;
                }
            }

            if (!empty($ruleParts)) {
                // Guardamos las reglas como ARRAY de reglas sueltas (no las unimos con
                // '|'): así un patrón regex con '|' (alternancia) se conserva íntegro.
                // RuleEngine::parse() acepta tanto este array como el string legacy
                // que pueda devolver una subclase que sobreescriba rules().
                $rules[$name] = $ruleParts;
            }
        }

        self::$cachedRules[$class]    = $rules;
        self::$cachedMessages[$class] = $messages;
        return $rules;
    }

    /**
     * Construye y cachea la metadata de Reflection para la clase dada.
     * Solo se ejecuta una vez por clase durante el ciclo de vida del proceso.
     */
    private static function buildCache(string $class): void
    {
        if (isset(self::$cachedMetadata[$class])) {
            return;
        }

        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $metadata = [];
        foreach ($properties as $property) {
            $type = $property->getType();

            $metadata[$property->getName()] = [
                'property'  => $property,
                'typeName'  => $type?->getName(),
                'isBuiltin' => $type?->isBuiltin() ?? false,
                'nullable'  => $type?->allowsNull() ?? true,
            ];
        }

        self::$cachedMetadata[$class] = $metadata;
    }

    /**
     * Evalúa TODAS las reglas de un campo con fail-fast: retorna el primer mensaje
     * de error, o null si el campo pasa. Delega la semántica en el motor único
     * {@see RuleEngine}, compartido con HexaLite\Validation\Validator.
     *
     * @param string|array<int, string> $ruleDef  reglas del campo (array de reglas
     *        sueltas — formato nuevo — o string "a|b:c" legacy de una subclase).
     * @param array<string, array<string, string>> $messages  mensajes custom por campo/regla.
     */
    private static function evaluateField(string $field, mixed $value, string|array $ruleDef, array $allData, array $messages): ?string
    {
        $parsed = RuleEngine::parse($ruleDef);

        // null + nullable declarado → el campo pasa sin evaluar el resto.
        if ($value === null && RuleEngine::isNullable($parsed)) {
            return null;
        }

        foreach ($parsed as $r) {
            $name  = $r['name'];
            $param = $r['param'];

            if ($name === 'custom') {
                if (!RuleEngine::runCustom($param, $value, $allData)) {
                    $key = "custom:{$param}";
                    return $messages[$field][$key] ?? RuleEngine::customDefaultMessage((string) $param, $field);
                }
                continue;
            }

            if (!RuleEngine::passes($name, $value, $param, $allData, $field)) {
                return $messages[$field][$name] ?? RuleEngine::message($name, $field, $param, $value);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILIDADES PÚBLICAS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Exporta el DTO como array.
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Exporta el DTO como JSON.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Retorna solo las propiedades indicadas.
     *
     *   $dto->only(['email', 'name'])
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->toArray(), array_flip($keys));
    }

    /**
     * Retorna todas las propiedades excepto las indicadas.
     *
     *   $dto->except(['password', 'password_confirmation'])
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    /**
     * Verifica si una propiedad está inicializada y no es null.
     *
     *   $dto->has('avatar')
     */
    public function has(string $key): bool
    {
        // Acceso directo a propiedad pública — sin Reflection, sin setAccessible()
        return isset($this->$key);
    }

    /**
     * Usado por el Router para detectar si una clase es un DTO inyectable.
     *
     *   Dtos::isDtoClass(LoginDto::class)   // true
     *   Dtos::isDtoClass(UserService::class) // false
     */
    final public static function isDtoClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        return is_subclass_of($className, self::class);
    }
}
