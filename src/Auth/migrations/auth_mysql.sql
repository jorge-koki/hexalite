-- ============================================================================
-- HexaLite · Kit de autenticación — esquema para MySQL / MariaDB
-- ============================================================================
-- Aplicar con:
--   mysql -u USER -p BASE < vendor/hexalite/framework/src/Auth/migrations/auth_mysql.sql
--
-- MySQL no tiene esquemas dentro de una base (un "schema" ES una base), así que
-- las tablas se llaman `auth_users`, `auth_roles`, etc. — el prefijo que usa por
-- defecto PdoUserRepository cuando el driver es mysql.
--
-- Idempotente: se puede volver a ejecutar sin romper nada.
-- ============================================================================

-- ────────────────────────────────────────────────────────────────────────────
-- Usuarios
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_users (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                        VARCHAR(190)    NOT NULL,

    -- utf8mb4_unicode_ci es INSENSIBLE a mayúsculas: el UNIQUE de abajo ya
    -- impide que "Ana@x.com" y "ana@x.com" convivan, así que aquí no hace falta
    -- el índice funcional sobre lower(email) que sí necesita PostgreSQL.
    email                       VARCHAR(190)    NOT NULL,

    -- NULL a propósito: una cuenta que solo entra con Google no tiene contraseña.
    password                    VARCHAR(255)    NULL,

    -- 'A' activa | 'I' dada de baja.
    status                      CHAR(1)         NOT NULL DEFAULT 'A',

    -- Verificación de correo (BLANDA por defecto).
    email_verified_at           DATETIME        NULL,
    email_verification_token    VARCHAR(128)    NULL,
    email_verification_sent_at  DATETIME        NULL,

    -- Restablecimiento de contraseña: token de un solo uso y con expiración.
    password_reset_token        VARCHAR(128)    NULL,
    password_reset_sent_at      DATETIME        NULL,
    password_reset_expires_at   DATETIME        NULL,

    -- Revocación por marca de agua: un JWT vale solo si su `iat` es >= este epoch.
    tokens_valid_after          BIGINT          NOT NULL DEFAULT 0,

    -- Claim `sub` de Google: identificador estable de la cuenta.
    google_sub                  VARCHAR(190)    NULL,
    google_linked_at            DATETIME        NULL,

    last_login_at               DATETIME        NULL,
    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY users_email_unique (email),
    -- UNIQUE con NULLs: MySQL permite tantos NULL como quieras en un índice
    -- único, así que esto equivale al índice parcial de PostgreSQL — dos cuentas
    -- no comparten Google y quienes no lo usan no estorban.
    UNIQUE KEY users_google_sub_unique (google_sub),
    KEY users_email_verification_token_idx (email_verification_token),
    KEY users_password_reset_token_idx (password_reset_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────────
-- Roles y permisos (RBAC mínimo)
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY roles_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY permissions_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_arp_role       FOREIGN KEY (role_id)       REFERENCES auth_roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_arp_permission FOREIGN KEY (permission_id) REFERENCES auth_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED    NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY user_roles_user_id_idx (user_id),
    CONSTRAINT fk_aur_user FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_aur_role FOREIGN KEY (role_id) REFERENCES auth_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────────────
-- Semillas
-- ────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO auth_roles (name, description) VALUES
    ('admin', 'Acceso total'),
    ('user',  'Usuario estándar');

INSERT IGNORE INTO auth_permissions (name, description) VALUES
    ('users:read',   'Ver usuarios'),
    ('users:write',  'Crear y editar usuarios'),
    ('users:delete', 'Eliminar usuarios');

-- El rol admin recibe TODOS los permisos existentes.
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM auth_roles r
 CROSS JOIN auth_permissions p
 WHERE r.name = 'admin';
