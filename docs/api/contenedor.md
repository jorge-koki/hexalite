# API — Contenedor de dependencias

[← Índice de la referencia](README.md)

- [`Container`](#container) · [`#[Inject]`](#inject) · [`ProviderInterface`](#providerinterface) · [Excepciones](#excepciones)

---

## Container

`HexaLite\Container\Container` — `src/Container/Container.php`

Contenedor con *autowiring* de constructores, singletons, factorías perezosas y
servicios *transient*.

```php
__construct(?string $cacheFile = null, bool $isProduction = false)
```

Con `$isProduction = true` y un `$cacheFile`, la metadata de constructores y los
enlaces se cargan del caché al arrancar y se guardan al destruirse el contenedor.

### Registro

| Método | Qué registra |
|---|---|
| `set(string $id, mixed $instance): void` | Una instancia ya construida (singleton). |
| `setFactory(string $id, callable $factory): void` | Una factoría **perezosa**; su resultado se cachea. Recibe el contenedor. |
| `transient(string $id, callable $factory): void` | Una factoría que devuelve **instancia nueva en cada `get()`**, sin cachear. |
| `bind(string $interface, string $implementation): void` | Un alias de interfaz a clase concreta. |

`setFactory()` y `transient()` lanzan `InvalidArgumentException` si lo que reciben no
es invocable; `bind()` lanza [`NotFoundException`](#excepciones) si la implementación
no existe.

### Resolución

| Método | Devuelve |
|---|---|
| `has(string $id): bool` | ¿Se puede resolver? Cuenta instancias, factorías, transients, enlaces y clases existentes. |
| `get(string $id): mixed` | La instancia, construyéndola si hace falta. |

`get()` resuelve en este orden: enlace → transient → instancia cacheada → factoría →
autowiring de la clase.

### Autowiring

El contenedor lee el constructor y resuelve cada parámetro:

- **Tipos de clase** → se resuelven recursivamente desde el contenedor.
- **Tipos nullable** cuyo servicio no está registrado → se inyecta `null`.
- **Tipos primitivos** → se usa su valor por defecto. Si no lo tienen, lanza
  [`ContainerException`](#excepciones): el contenedor no puede adivinar un `string`.
- **Parámetros sin tipo nombrado** → `ContainerException`.
- **`#[Inject]`** sobre un parámetro cambia el identificador que se resuelve.

Las dependencias circulares se detectan durante la resolución y lanzan
[`CircularDependencyException`](#excepciones) en vez de caer en recursión infinita.

### Singleton por defecto, y cuándo no

Toda clase resuelta por autowiring se cachea y se reutiliza durante la vida del
contenedor. **Bajo PHP-FPM eso es correcto**: un proceso por petición, así que el
contenedor muere con la respuesta.

Bajo un runtime demonio, donde el contenedor vive entre peticiones, un servicio con
estado compartiría datos entre peticiones o inquilinos. Para esos casos existe
`transient()`.

### Caché en producción

La metadata se exporta como array PHP nativo y se carga con `require`, para que la
sirva OPcache sin E/S. La escritura es **atómica** (fichero temporal + `rename`), lo
cual es necesario de verdad: varios workers de FPM pueden guardar a la vez desde su
destructor, y un `require` de un fichero a medio escribir sería un error fatal de
parseo. Tras el `rename` se invalida la entrada de OPcache.

Los fallos de caché se registran en `error_log` y no interrumpen la petición: si el
caché no se puede leer, se vuelve a escanear.

---

## Inject

`HexaLite\Attributes\Inject` — `src/Attributes/Inject.php`

```php
#[Attribute(TARGET_PARAMETER | TARGET_PROPERTY)]
__construct(BackedEnum|string $connection)

public readonly string $alias;
```

Inyecta por alias en vez de por tipo — el caso típico es elegir una conexión de base
de datos concreta cuando hay varias.

Acepta un enum respaldado (se usa su `->value`) o directamente un string, así que es
**agnóstico a tu aplicación**: cualquier enum `: string` propio funciona sin que el
framework lo conozca.

```php
public function __construct(
    #[Inject(DbConnection::REPLICA)] private DatabaseInterface $db
) {}

public function __construct(
    #[Inject('database.conexion2')] private DatabaseInterface $db
) {}
```

---

## ProviderInterface

`HexaLite\Providers\ProviderInterface` — `src/Providers/ProviderInterface.php`

```php
register(): void
boot(): void
```

Contrato de los proveedores de servicios. Por convención, `register()` solo registra
enlaces en el contenedor y `boot()` hace el trabajo que necesita que todo esté ya
registrado. [`AuthServiceProvider`](auth.md#authserviceprovider) es la implementación
que trae el framework.

---

## Excepciones

`HexaLite\Container\*` — `src/Container/`

| Excepción | Cuándo |
|---|---|
| `ContainerException` | Base de todas. Constructor no analizable, clase no instanciable, parámetro primitivo sin valor por defecto, o fallo al construir. |
| `NotFoundException` *(extiende `ContainerException`)* | El identificador no se puede resolver: la clase no existe o el enlace apunta a una clase inexistente. |
| `CircularDependencyException` *(extiende `ContainerException`)* | A depende de B que depende de A. |

Las tres extienden `\Exception`, así que un solo `catch (ContainerException $e)` cubre
cualquier fallo de resolución.
