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

## Estructura

```
examples/
├── public/
│   ├── index.php                 # front controller del ejemplo básico
│   └── auth.php                  # front controller con el kit de autenticación
└── src/
    ├── Controllers/
    │   ├── HelloController.php    # rutas simples + parámetro {name}
    │   ├── UserController.php     # DI, {id} int, DTO, HttpException
    │   └── AdminController.php    # rutas protegidas: rol, permisos, throttle
    ├── Dtos/CreateUserDto.php     # reglas por atributos
    ├── Middlewares/RequestIdMiddleware.php
    └── Services/UserRepository.php
```
