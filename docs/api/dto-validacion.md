# API — DTOs y validación

[← Índice de la referencia](README.md)

Los dos caminos de validación de HexaLite —DTOs autoinyectados y
`$request->validate()`— comparten un único motor de reglas, así que su semántica
no puede divergir.

- [`Dtos`](#dtos)
- [Atributos de validación](#atributos-de-validación)
- [`CustomValidator`](#customvalidator)
- [`Validator`](#validator)
- [`RuleEngine`](#ruleengine)
- [Catálogo de reglas](#catálogo-de-reglas)

---

## Dtos

`HexaLite\Http\DTO\Dtos` — `src/Http/DTO/Dtos.php`

Clase base abstracta de los DTOs autovalidados. Declara las propiedades públicas con
sus atributos de validación y el Router se encarga del resto: al ver una subclase de
`Dtos` en la firma de un controlador, hidrata y valida, y responde 422 si algo falla.

```php
final class CreateUserDto extends Dtos
{
    #[IsRequired] #[IsString] #[Min(3)] #[Max(50)] #[NoHtml]
    public readonly string $name;

    #[IsRequired] #[IsEmail]
    public readonly string $email;

    #[Nullable] #[IsInt] #[Min(18)]
    public readonly ?int $age;
}

#[Route('/users', 'POST')]
public function crear(CreateUserDto $dto): Response { /* ya está validado */ }
```

### Fábricas

| Método | Qué hace |
|---|---|
| `static fromArray(array $data): static` | Valida el array y devuelve el DTO hidratado. Es lo que llama el Router. `final`. |
| `static fromRequest(Request $request): static` | Igual, pero partiendo de la petición (vía `$request->validate()`). Para invocarlo a mano desde un controlador. |

Ambas lanzan [`ValidationException`](http.md#validationexception) si la validación falla.

### Contrato sobreescribible

| Método | Por defecto |
|---|---|
| `static rules(): array` | Se generan leyendo los atributos de cada propiedad pública. |
| `static messages(): array` | Se generan desde el argumento `message:` de cada atributo. |
| `static casts(): array` (protegido) | `[]`. Mapea `campo => ClaseDeValor`; la clase debe tener un `fromArray(array): static`. |

```php
protected static function casts(): array
{
    return ['route_options' => RouteOptions::class];
}
```

### Utilidades de instancia

| Método | Devuelve |
|---|---|
| `toArray(): array` | Todas las propiedades. |
| `toJson(): string` | Lo mismo, en JSON con `JSON_UNESCAPED_UNICODE`. |
| `only(array $keys): array` | Solo esas propiedades. |
| `except(array $keys): array` | Todas menos esas. |
| `has(string $key): bool` | ¿La propiedad está inicializada y no es `null`? |
| `static isDtoClass(string $className): bool` | ¿Esa clase es un DTO inyectable? Lo usa el Router. `final`. |

### Comportamiento de la validación

**Campos no declarados se rechazan.** Un campo enviado que no está en las reglas
produce «El campo 'x' no está permitido.» — el DTO define la superficie completa de
entrada, no un mínimo.

**Fail-fast por campo.** Se reporta el primer error de cada campo, no todos. (El
[`Validator`](#validator) del otro camino sí los acumula todos: es su comportamiento
histórico y se mantuvo a propósito.)

**Notación de punto para campos anidados.** Una regla `'route_options.routeType'`
navega la estructura antes de evaluar. Solo los campos de primer nivel se devuelven
hidratados.

**Casteo al tipo declarado.** Antes de asignar, los valores se convierten al tipo de
la propiedad (`int`, `float`, `bool` vía `FILTER_VALIDATE_BOOLEAN`, `string`). Un
campo ausente cuya propiedad sea nullable se asigna `null`.

**Propiedades `readonly`.** Se asignan con una *closure* enlazada a la instancia, así
que un DTO puede ser inmutable sin renunciar a la hidratación automática.

**Caché por proceso.** La metadata de reflexión, las reglas, los mensajes y la factoría
de instancias se cachean por clase la primera vez, y se reutilizan durante toda la
vida del proceso.

---

## Atributos de validación

`HexaLite\Http\DTO\Attributes\*` — `src/Http/DTO/Attributes/`

Todos son `readonly`, apuntan a `TARGET_PROPERTY`, implementan
[`ValidationAttribute`](#validationattribute) y aceptan un `message:` final para
personalizar el error.

| Atributo | Firma | Regla que genera |
|---|---|---|
| `#[IsRequired]` | `(?string $message = null)` | `required` |
| `#[Nullable]` | `(?string $message = null)` | `nullable` |
| `#[IsString]` | `(?string $message = null)` | `string` |
| `#[IsInt]` | `(?string $message = null)` | `int` |
| `#[IsFloat]` | `(?string $message = null)` | `float` |
| `#[IsNumeric]` | `(?string $message = null)` | `numeric` |
| `#[IsBoolean]` | `(?string $message = null)` | `boolean` |
| `#[IsEmail]` | `(?string $message = null)` | `email` |
| `#[IsUrl]` | `(?string $message = null)` | `url` |
| `#[IsArray]` | `(?string $message = null)` | `array` |
| `#[Min]` | `(int\|float $value, ?string $message = null)` | `min:{value}` |
| `#[Max]` | `(int\|float $value, ?string $message = null)` | `max:{value}` |
| `#[In]` | `(array $values, ?string $message = null)` | `in:a,b,c` |
| `#[Regex]` | `(string $pattern, ?string $message = null)` | `regex:{pattern}` |
| `#[DateFormat]` | `(string $format = '', ?string $message = null)` | `date` o `date:{format}` |
| `#[NoHtml]` | `(?string $message = null)` | `no_html` |
| `#[Confirmed]` | `(?string $message = null)` | `confirmed` |
| `#[Custom]` | `(string $validator, ?string $message = null)` | `custom:{FQCN}` — **repetible** |

Todos exponen `toRuleString(): string`, `getRuleName(): string` y
`getMessage(): ?string`.

`#[Custom]` es el único repetible, y su `getRuleName()` incluye el FQCN del validador
para que varios `#[Custom]` sobre el mismo campo no compartan clave de mensaje.

```php
#[IsRequired(message: 'Necesitamos tu correo.')]
#[IsEmail(message: 'Ese correo no tiene buena pinta.')]
public readonly string $email;
```

### ValidationAttribute

`HexaLite\Http\DTO\Attributes\ValidationAttribute`

Contrato de los atributos. Impleméntalo para añadir reglas propias que se declaren
igual que las incluidas.

```php
toRuleString(): string    // "name" o "name:param"
getRuleName(): string     // nombre canónico, sin parámetros
getMessage(): ?string     // mensaje custom, o null
```

---

## CustomValidator

`HexaLite\Http\DTO\Validators\CustomValidator` — `src/Http/DTO/Validators/CustomValidator.php`

Contrato de los validadores invocados desde `#[Custom(validator: MiClase::class)]`.

```php
passes(mixed $value, array $allData = []): bool
defaultMessage(string $field): string
```

`$allData` trae los datos completos, así que sirve para validaciones que cruzan
campos. Las implementaciones **deben tener constructor sin argumentos**: se instancian
con `new $class()` y se cachean por proceso.

```php
final class StrongPasswordValidator implements CustomValidator
{
    public function passes(mixed $value, array $allData = []): bool
    {
        return is_string($value) && preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $value) === 1;
    }

    public function defaultMessage(string $field): string
    {
        return "El campo '{$field}' debe tener 8 caracteres, mayúscula, minúscula y número.";
    }
}
```

---

## Validator

`HexaLite\Validation\Validator` — `src/Validation/Validator.php`

El validador que hay detrás de `$request->validate()`. Reglas en formato string por
campo, y **acumula todos los errores de cada campo** (a diferencia del fail-fast de
los DTOs).

```php
__construct(array $data)
validate(array $rules, array $customMessages = []): array
```

`validate()` devuelve los errores indexados por campo (array vacío si todo es válido);
no lanza. Quien lanza es `$request->validate()`.

```php
$v = new Validator($datos);
$errores = $v->validate(
    ['email' => 'required|email', 'edad' => 'nullable|int|min:18'],
    ['email' => ['email' => 'Correo inválido.']]
);
```

Un `nullable` con valor `null` salta el resto de las reglas del campo.

---

## RuleEngine

`HexaLite\Validation\RuleEngine` — `src/Validation/RuleEngine.php`

Motor único: parseo, semántica de cada regla y mensajes por defecto. Tanto
[`Validator`](#validator) como [`Dtos`](#dtos) delegan aquí, y por eso existe **una
sola fuente de verdad**.

| Método | Qué hace |
|---|---|
| `static parse(string\|array $rules): array` | Normaliza a una lista de `{name, param}`. |
| `static isNullable(array $parsed): bool` | ¿La lista declara `nullable`? |
| `static passes(string $name, mixed $value, ?string $param, array $allData, string $field): bool` | Evalúa una regla. |
| `static runCustom(?string $class, mixed $value, array $allData): bool` | Ejecuta un validador `custom`, cacheando la instancia. |
| `static customDefaultMessage(string $class, string $field): string` | Mensaje por defecto de un validador custom. |
| `static message(string $name, string $field, ?string $param, mixed $value = null): string` | Mensaje por defecto de una regla fallida. |

### Los dos formatos de reglas

```php
"required|min:3"              // string legacy: se separa por '|'
["required", "min:3"]         // array: cada elemento es una regla suelta
```

El formato array **resuelve el problema del delimitador**: un patrón regex que
contenga `|` (alternancia) se conserva íntegro, porque solo se parte cada regla suelta
por su primer `:`. Los atributos de los DTOs generan siempre el formato array; el
string se mantiene para `$request->validate()` y para subclases que sobreescriban
`rules()`.

El parseo de strings se cachea, con un tope de 1.000 entradas para acotar la memoria
en procesos de larga vida.

`runCustom()` lanza `LogicException` si la clase no existe o no implementa
[`CustomValidator`](#customvalidator).

---

## Catálogo de reglas

Semántica exacta, tal como la implementa `RuleEngine::passes()`.

| Regla | Pasa cuando |
|---|---|
| `required` | El valor no es `null`, ni `''`, ni un array vacío. **Acepta `false`, `0` y `"0"`.** |
| `nullable` | Siempre. Su efecto es que un valor `null` salte el resto de reglas del campo. |
| `string` | Es string o numérico. |
| `int` / `integer` | `FILTER_VALIDATE_INT` lo acepta. |
| `numeric` / `float` | `is_numeric()`. |
| `boolean` / `bool` | Es booleano, o `FILTER_VALIDATE_BOOLEAN` lo interpreta. |
| `email` | `FILTER_VALIDATE_EMAIL`. |
| `url` | `FILTER_VALIDATE_URL`. |
| `array` | Es un array. |
| `min:n` | String: **longitud** ≥ n (multibyte). Array: **cantidad** ≥ n. Numérico: **valor** ≥ n. |
| `max:n` | Igual que `min`, invertido. |
| `in:a,b,c` | El valor está en la lista (comparación estricta como string). Un array nunca pasa. |
| `regex:patrón` | Casa. Si el patrón no trae delimitador, se envuelve en `/.../`. |
| `no_html` | No contiene `<` ni `>` ni caracteres de control (salvo tab, LF y CR). |
| `confirmed` | `{campo}_confirmation` tiene el mismo valor. |
| `date` / `date:formato` | Fecha válida; con formato, además debe re-formatearse idéntica. |
| *(desconocida)* | Siempre pasa (comportamiento heredado). |

Dos detalles que evitan sorpresas:

- **Salvo `required` y `array`, las reglas dejan pasar `null` y `''`.** La presencia se
  exige con `required`, no con la regla de tipo. Es lo que hace que un campo opcional
  vacío no genere errores de tipo.
- **`min`/`max` miden los strings por longitud, siempre.** Un teléfono como
  `"+5219381040076"` se mide en caracteres, no como número.

`no_html` es defensa en profundidad para campos de identidad (nombres de persona, de
empresa): no tienen razón legítima para contener `<>`, así que una carga como
`<script>…` se rechaza **en la entrada**, no solo se escapa en la salida.
