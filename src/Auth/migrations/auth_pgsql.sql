-- ============================================================================
-- HexaLite · Kit de autenticación — esquema para PostgreSQL
-- ============================================================================
-- Aplicar con:
--   psql "$DATABASE_URL" -f vendor/hexalite/framework/src/Auth/migrations/auth_pgsql.sql
--
-- Todo el script es IDEMPOTENTE: se puede volver a ejecutar sin romper nada
-- (IF NOT EXISTS en todas partes y ON CONFLICT DO NOTHING en las semillas).
-- ============================================================================

CREATE SCHEMA IF NOT EXISTS auth;

-- ────────────────────────────────────────────────────────────────────────────
-- Usuarios
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth.users (
    id                          bigserial   PRIMARY KEY,
    name                        text        NOT NULL,
    email                       text        NOT NULL,

    -- NULL a propósito: una cuenta que solo entra con Google no tiene contraseña.
    -- El login por contraseña la trata como credencial incorrecta (ver User::verifyPassword).
    password                    text        NULL,

    -- 'A' activa | 'I' dada de baja.
    status                      char(1)     NOT NULL DEFAULT 'A',

    -- Verificación de correo. Por defecto es BLANDA: el usuario entra igual y el
    -- front le muestra un aviso. Se endurece con AUTH_REQUIRE_VERIFIED_EMAIL=true.
    email_verified_at           timestamptz NULL,
    email_verification_token    text        NULL,
    email_verification_sent_at  timestamptz NULL,

    -- Restablecimiento de contraseña. El token es de un solo uso y con expiración.
    password_reset_token        text        NULL,
    password_reset_sent_at      timestamptz NULL,
    password_reset_expires_at   timestamptz NULL,

    -- Revocación de sesiones por marca de agua: un JWT vale solo si su `iat` es
    -- >= este epoch. Cambiar la contraseña o cerrar sesión lo pone en `now()` y
    -- mata de golpe todos los tokens anteriores, sin listas negras de `jti`.
    tokens_valid_after          bigint      NOT NULL DEFAULT 0,

    -- Identificador ESTABLE que Google da a cada cuenta (claim `sub`). Se resuelve
    -- por aquí y no por correo, porque el correo de una cuenta de Google sí cambia.
    google_sub                  text        NULL,
    google_linked_at            timestamptz NULL,

    last_login_at               timestamptz NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);

-- El correo identifica al usuario al entrar, así que debe ser ÚNICO. El índice
-- va sobre lower(email) para que la unicidad sea insensible a mayúsculas: sin
-- esto, "Ana@x.com" y "ana@x.com" serían dos cuentas distintas y el login por
-- LOWER(email) encontraría dos filas.
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique_idx
    ON auth.users (lower(email));

-- Único pero solo entre quienes SÍ usan Google (índice parcial): dos cuentas no
-- pueden compartir el mismo Google, y los NULL de los demás no estorban.
CREATE UNIQUE INDEX IF NOT EXISTS users_google_sub_unique_idx
    ON auth.users (google_sub) WHERE google_sub IS NOT NULL;

-- Los tokens se buscan por su valor exacto en cada verificación/reset.
CREATE INDEX IF NOT EXISTS users_email_verification_token_idx
    ON auth.users (email_verification_token) WHERE email_verification_token IS NOT NULL;

CREATE INDEX IF NOT EXISTS users_password_reset_token_idx
    ON auth.users (password_reset_token) WHERE password_reset_token IS NOT NULL;

-- ────────────────────────────────────────────────────────────────────────────
-- Roles y permisos (RBAC mínimo)
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth.roles (
    id          serial PRIMARY KEY,
    name        text   NOT NULL UNIQUE,
    description text   NULL
);

CREATE TABLE IF NOT EXISTS auth.permissions (
    id          serial PRIMARY KEY,
    name        text   NOT NULL UNIQUE,
    description text   NULL
);

CREATE TABLE IF NOT EXISTS auth.role_permissions (
    role_id       integer NOT NULL REFERENCES auth.roles(id)       ON DELETE CASCADE,
    permission_id integer NOT NULL REFERENCES auth.permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE IF NOT EXISTS auth.user_roles (
    user_id bigint  NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
    role_id integer NOT NULL REFERENCES auth.roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- Los permisos se consultan en cada petición con #[Permission]: el índice por
-- usuario evita un scan de la tabla de asignaciones en cada request.
CREATE INDEX IF NOT EXISTS user_roles_user_id_idx ON auth.user_roles (user_id);

-- ────────────────────────────────────────────────────────────────────────────
-- Semillas
-- ────────────────────────────────────────────────────────────────────────────
-- `user` es el rol por defecto de quien se registra (AUTH_DEFAULT_ROLES).
INSERT INTO auth.roles (name, description) VALUES
    ('admin', 'Acceso total'),
    ('user',  'Usuario estándar')
ON CONFLICT (name) DO NOTHING;

INSERT INTO auth.permissions (name, description) VALUES
    ('users:read',   'Ver usuarios'),
    ('users:write',  'Crear y editar usuarios'),
    ('users:delete', 'Eliminar usuarios')
ON CONFLICT (name) DO NOTHING;

-- El rol admin recibe TODOS los permisos existentes.
INSERT INTO auth.role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM auth.roles r
 CROSS JOIN auth.permissions p
 WHERE r.name = 'admin'
ON CONFLICT DO NOTHING;
