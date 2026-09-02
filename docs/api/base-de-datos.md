# API — Base de datos

[← Índice de la referencia](README.md)

- [`DatabaseManager`](#databasemanager) · [`DatabaseInterface`](#databaseinterface) · [`PDODatabase`](#pdodatabase) · [`QueryResult`](#queryresult)

---

## DatabaseManager

`HexaLite\Database\DatabaseManager` — `src/Database/DatabaseManager.php`

Registro perezoso de conexiones. Su razón de ser es el **autodescubrimiento**:
`fromEnv()` mira el entorno y activa solo las conexiones cuyas variables estén llenas.
Sin variables no se registra nada y el framework arranca igual — útil para APIs sin
base de datos.

**Las conexiones se abren la primera vez que se piden, nunca al arrancar.** Una
petición que no toca la base de datos no paga el handshake.

```php
__construct(array $configs = [], ?string $default = null)
static fromEnv(): DatabaseManager
```

### Descubrimiento por entorno

| Grupo | Conexión que registra |
|---|---|
| `DB_*` | `default`. El driver sale de `DB_CONNECTION`/`DB_DRIVER`; si no se indica, se deduce del puerto (5432 → pgsql, 3306 → mysql) y por defecto es pgsql. |
| `PGSQL_*` (o `POSTGRES_*`) | `pgsql` |
| `MYSQL_*` | `mysql` |

Variables de cada grupo: `<P>_HOST`, `<P>_PORT`, `<P>_NAME` (o `<P>_DATABASE`),
`<P>_USER` (o `<P>_USERNAME`), `<P>_PASSWORD`, `<P>_CHARSET`, `<P>_SCHEMA` (solo
pgsql: fija el `search_path`).

Un grupo se considera configurado solo si trae **host y nombre de base de datos**; si
falta alguno, se ignora en silencio. `DB_CONNECTION=sqlite` con `DB_NAME=/ruta/app.sqlite`
también funciona (solo necesita el driver y la ruta).

La conexión por defecto es `default` si existe; si no, la primera descubierta — así una
app que solo llena `MYSQL_*` funciona sin nombrar la conexión en cada inyección.

### API

| Método | Devuelve |
|---|---|
| `connection(?string $name = null): DatabaseInterface` | Abre o reutiliza una conexión. Sin nombre, la de por defecto. |
| `isEmpty(): bool` | ¿No hay ninguna configurada? |
| `has(string $name): bool` | ¿Existe esa configuración? |
| `names(): array` | Nombres de las conexiones configuradas. |
| `defaultName(): ?string` | Nombre de la conexión por defecto. |
| `config(string $name): ?array` | Su configuración cruda. |
| `addConnection(string $name, array $config, bool $asDefault = false): void` | Registra o reemplaza una conexión en caliente. |
| `static make(array $config): DatabaseInterface` | Crea una conexión suelta desde un array. |
| `static dsn(array $config): string` | Arma el DSN de PDO. |

`connection()` lanza `RuntimeException` si no hay ninguna conexión configurada o si el
nombre pedido no existe (el mensaje lista las disponibles).

`make()` **comprueba antes que el driver de PDO esté instalado**, porque el error
nativo por driver ausente («could not find driver») no dice cuál falta ni cómo
instalarlo. `dsn()` lanza `InvalidArgumentException` con un driver no soportado.

---

## DatabaseInterface

`HexaLite\Database\DatabaseInterface` — `src/Database/DatabaseInterface.php`

```php
query(string $sql, array $params = []): QueryResult
lastInsertId(): string|false
transaction(callable $callback): mixed
close(): void
```

`transaction()` recibe un callback con el ejecutor y devuelve lo que este devuelva.

---

## PDODatabase

`HexaLite\Database\PDODatabase` — `src/Database/PDODatabase.php`

Implementación síncrona sobre PDO.

```php
__construct(string $dsn, string $user, string $password, array $options = [])
getPDO(): PDO
```

Opciones por defecto, que `$options` puede sobreescribir:

| Atributo | Valor | Por qué |
|---|---|---|
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | Los errores lanzan, no se ignoran. |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | Filas como arrays asociativos. |
| `ATTR_EMULATE_PREPARES` | `false` | Sentencias preparadas reales en el servidor. |
| `ATTR_PERSISTENT` | `false` | PHP-FPM ya gestiona el pool por worker. |

`query()` prepara y ejecuta con los parámetros (nombrados o posicionales, PDO decide),
y envuelve cualquier `PDOException` en un `RuntimeException` con la anterior encadenada.

`transaction()` hace `commit` al terminar y **`rollBack` automático** ante cualquier
`Throwable`, que se vuelve a lanzar.

`close()` suelta la referencia a PDO, lo que basta para cerrar el socket. Usar la
conexión después lanza `RuntimeException`.

---

## QueryResult

`HexaLite\Database\QueryResult` — `src/Database/QueryResult.php`

Envoltorio de `PDOStatement` que implementa `IteratorAggregate`.

```php
__construct(PDOStatement $result)

fetch(): ?array          // siguiente fila, o null. Avanza el cursor (streaming).
fetchAll(): array        // todas las filas. Cacheado.
rowCount(): int          // filas afectadas
getIterator(): Traversable
```

**`fetchAll()` es repetible.** El resultado se guarda en un buffer perezoso, así que
llamarlo dos veces —o iterar después de haberlo llamado, o hacer dos `foreach`
seguidos— funciona. Sin ese buffer, el cursor *forward-only* de PDO se consumiría y la
segunda lectura devolvería vacío.

`fetch()` sí avanza el cursor: es la vía de *streaming* para resultados grandes.
