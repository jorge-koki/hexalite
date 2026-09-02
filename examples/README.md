# Ejemplo de HexaLite

Una mini-API que muestra enrutado por atributos, inyección de dependencias,
DTOs autovalidados, parámetros de ruta, un middleware global y manejo de errores.

## Ejecutar

Desde la raíz del paquete:

```bash
composer install
php -S localhost:8000 -t examples/public
```

## Probar

```bash
# Rutas básicas y parámetro dinámico
curl localhost:8000/
curl localhost:8000/hello/ada

# Servicio inyectado (UserRepository) + parámetro {id} casteado a int
curl localhost:8000/users
curl localhost:8000/users/1
curl localhost:8000/users/999          # → 404 { "error": "Request Failed", "code": "USER_NOT_FOUND" }

# DTO autovalidado
curl -X POST localhost:8000/users \
     -H 'Content-Type: application/json' \
     -d '{"name":"Grace","email":"grace@example.com"}'      # → 201

curl -X POST localhost:8000/users \
     -H 'Content-Type: application/json' \
     -d '{"name":"x"}'                                       # → 422 (validación)
```

Cada respuesta incluye el header `X-Request-Id` que añade el middleware global.

## Ejemplo de autenticación

`public/auth.php` arranca el **kit de autenticación completo** (alta, login,
verificación de correo, restablecer/cambiar contraseña, Google, roles y
permisos). A diferencia del ejemplo de arriba, este sí necesita una base de datos.

```bash
# 1. Esquema (elige el de tu motor)
psql "$DATABASE_URL" -f src/Auth/migrations/auth_pgsql.sql
# mysql -u user -p base < src/Auth/migrations/auth_mysql.sql

# 1b. (PostgreSQL) Un administrador ya creado, para no depender del registro
#     ni del correo de verificación: admin@example.com / CambiaEsto1!
psql "$DATABASE_URL" -f src/Auth/migrations/seed_user_pgsql.sql

# 2. Configuración
cp .env.example .env
php -r 'echo "JWT_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;'          # pégala en el .env
php -r 'echo "JWT_REFRESH_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;'  # y esta también

# 3. Arrancar
php -S localhost:8000 -t examples/public examples/public/auth.php
```

```bash
# -c/-b guardan y reenvían las cookies, como haría un navegador
curl -c c.txt -X POST localhost:8000/auth/register \
     -H 'Content-Type: application/json' \
     -d '{"name":"Ada","email":"ada@example.com","password":"Contra5ena!","password_confirmation":"Contra5ena!"}'

curl -b c.txt localhost:8000/auth/me
curl localhost:8000/auth/health
```

La respuesta del registro trae `verification_url` porque `APP_ENV` no es
`production`: úsala para probar la verificación sin configurar correo. Si tampoco
hay mailer, el mensaje completo aparece en el log del servidor.

### Arrancar con un usuario ya creado

Si aplicaste `seed_user_pgsql.sql`, hay un administrador listo. `AdminController`
enseña cómo se ven las rutas protegidas desde el otro lado de la sesión:

```bash
# Entrar con el usuario semilla (guarda las cookies en c.txt)
curl -c c.txt -X POST localhost:8000/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@example.com","password":"CambiaEsto1!"}'

curl -b c.txt localhost:8000/admin/me                  # solo pide sesión
curl -b c.txt localhost:8000/admin/panel               # #[Roles('admin')]
curl -b c.txt localhost:8000/admin/usuarios            # #[Permission('users:read')]
curl -b c.txt localhost:8000/admin/usuarios/selector   # alternativas con |

# 403 a propósito: 'reports:read' no existe en las semillas.
# → {"error":"Forbidden","missing":["reports:read"]}
curl -b c.txt localhost:8000/admin/informes

# 401: sin cookie no hay sesión que mirar.
curl localhost:8000/admin/me
```

Para verlo pasar de 403 a 200, concede el permiso que falta:

```sql
INSERT INTO auth.permissions (name, description)
VALUES ('reports:read', 'Ver informes') ON CONFLICT (name) DO NOTHING;

INSERT INTO auth.role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth.roles r, auth.permissions p
 WHERE r.name = 'admin' AND p.name = 'reports:read'
ON CONFLICT DO NOTHING;
```

`POST /admin/exportar` lleva `#[Throttle(5, 60)]`, pero **solo cuenta con Redis
configurado**: PHP no comparte memoria entre peticiones, así que sin `REDIS_HOST`
el contador nace vacío en cada una y nunca se llega al 429.

El contrato para el frontend está en [`../docs/FRONTEND.md`](../docs/FRONTEND.md),
y el catálogo completo del framework en
[`../docs/CAPACIDADES.md`](../docs/CAPACIDADES.md).

## Ejemplo de arquitectura hexagonal

`public/hexagonal.php` levanta un **módulo completo** —`src/Modules/Informes/`—
organizado por capas, al estilo de una aplicación real. No necesita base de
datos: por defecto guarda en un fichero JSON.

```bash
php -S localhost:8000 -t examples/public examples/public/hexagonal.php
```

```bash
curl localhost:8000/informes?id_proyecto=1

# Crear (DTO válido)
curl -X POST localhost:8000/informes -H 'Content-Type: application/json' \
     -d '{"titulo":"Informe anual de seguridad","id_proyecto":1}'   # → 201

# Título demasiado corto: lo para el DTO, no llega al dominio
curl -X POST localhost:8000/informes -H 'Content-Type: application/json' \
     -d '{"titulo":"no","id_proyecto":1}'                           # → 422

# Regla de negocio: se publica una vez, y solo una
curl -X POST localhost:8000/informes/1/publicar                     # → 200
curl -X POST localhost:8000/informes/1/publicar                     # → 409
curl -X POST localhost:8000/informes/999/publicar                   # → 404
```

Para empezar de cero: `rm examples/var/informes.json`.

### Qué enseña, capa por capa

| Capa | Qué vive ahí | Qué NO puede aparecer |
| --- | --- | --- |
| `Domain/` | La entidad `Informe` y sus reglas (`publicar()`), el enum de estados, las excepciones del negocio y el **puerto** `InformeRepositoryInterface`. | SQL, `Request`, `Response`, atributos de validación HTTP. Ni un `use` que apunte a `Infrastructure`. |
| `Application/` | Casos de uso (`CrearInforme`, `ListarInformes`, `PublicarInforme`) que orquestan, y el DTO que valida la forma de la entrada. | Reglas de negocio (van en la entidad) y detalles de HTTP. |
| `Infrastructure/` | Adaptadores: el controlador de entrada y **tres** de salida —memoria, fichero JSON y PDO—. | Reglas de negocio. Solo traduce. |

La dirección de las dependencias es la clave: `Infrastructure` conoce a `Domain`,
y **nunca al revés**. Por eso los tres adaptadores son intercambiables sin tocar
una coma del negocio, y por eso el driver se elige en una sola línea:

```bash
INFORMES_DRIVER=memoria  php -S localhost:8000 -t examples/public examples/public/hexagonal.php
INFORMES_DRIVER=fichero  # por defecto
INFORMES_DRIVER=pdo      # con DB_HOST, DB_NAME, DB_USER, DB_PASSWORD definidas
```

Con `pdo`, aplica antes el esquema del módulo:

```bash
psql "$DATABASE_URL" -f examples/src/Modules/Informes/Infrastructure/Persistence/migrations/informes_pgsql.sql
# mysql -u user -p base < examples/src/Modules/Informes/Infrastructure/Persistence/migrations/informes_mysql.sql
```

> `memoria` y `fichero` **no son para producción**: PHP-FPM es share-nothing, así
> que el repositorio en memoria nace vacío en cada petición, y el de fichero
> bloquea el fichero entero en cada escritura.

### La prueba de que el esfuerzo vale

`tests/Examples/InformesHexagonalTest.php` ejercita todas las reglas del negocio
**sin base de datos, sin servidor web y sin un solo mock**: basta sustituir el
adaptador de persistencia por el de memoria, porque los dos cumplen el mismo
puerto.

```bash
vendor/bin/phpunit --filter InformesHexagonalTest --testdox
```

Seis pruebas en milisegundos. Ese es el pago de haber puesto las dependencias
mirando hacia adentro.

### Llevarlo a tu proyecto

En el ejemplo, el módulo cuelga de `HexaLite\Examples\Modules\` para no tocar el
`composer.json` del paquete. En una aplicación de verdad le darías su propia raíz
PSR-4:

```json
"autoload": {
    "psr-4": { "Modules\\": "modules/" }
}
```

y cada módulo (`modules/Informes/`, `modules/Facturas/`…) repetiría la misma
estructura de tres capas, con su propio provider.

## Estructura

```
examples/
├── public/
│   ├── index.php                 # front controller del ejemplo básico
│   ├── auth.php                  # front controller con el kit de autenticación
│   └── hexagonal.php             # front controller del ejemplo hexagonal
└── src/
    ├── Controllers/
    │   ├── HelloController.php    # rutas simples + parámetro {name}
    │   ├── UserController.php     # DI, {id} int, DTO, HttpException
    │   └── AdminController.php    # rutas protegidas: rol, permisos, throttle
    ├── Dtos/CreateUserDto.php     # reglas por atributos
    ├── Middlewares/RequestIdMiddleware.php
    ├── Services/UserRepository.php
    └── Modules/Informes/          # ── arquitectura hexagonal por módulo ──
        ├── InformesServiceProvider.php        # cablea puerto → adaptador
        ├── Domain/
        │   ├── Informe.php                    # entidad + reglas de negocio
        │   ├── EstadoInforme.php
        │   ├── Exceptions/
        │   └── Interfaces/
        │       └── InformeRepositoryInterface.php   # ← el PUERTO
        ├── Application/
        │   ├── Dtos/CrearInformeDto.php
        │   └── UseCases/                      # CrearInforme, ListarInformes, PublicarInforme
        └── Infrastructure/
            ├── Http/Controllers/InformeController.php    # adaptador de entrada
            └── Persistence/                              # adaptadores de salida
                ├── InMemoryInformeRepository.php
                ├── JsonFileInformeRepository.php
                ├── PdoInformeRepository.php
                └── migrations/
```
