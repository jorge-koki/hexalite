-- ============================================================================
-- HexaLite · Primer usuario administrador — PostgreSQL
-- ============================================================================
-- Arranca la API con una cuenta lista para iniciar sesión, sin pasar por el
-- registro ni por el correo de verificación. Es lo que hace falta para probar
-- las rutas protegidas (#[Roles], #[Permission]) desde el minuto cero.
--
-- Aplicar DESPUÉS del esquema:
--   psql "$DATABASE_URL" -f src/Auth/migrations/auth_pgsql.sql
--   psql "$DATABASE_URL" -f src/Auth/migrations/seed_user_pgsql.sql
--
-- Credenciales que crea:
--   correo:     admin@example.com
--   contraseña: CambiaEsto1!
--
-- ⚠️  ESTA CONTRASEÑA ES PÚBLICA: está escrita en el repositorio del framework.
--     Sirve para desarrollo. Antes de exponer la API a internet, cámbiala con
--     POST /auth/change-password o con el UPDATE del final de este archivo.
--
-- El script es IDEMPOTENTE: volver a ejecutarlo no duplica el usuario ni pisa
-- la contraseña si ya la cambiaste.
-- ============================================================================

-- ────────────────────────────────────────────────────────────────────────────
-- 1. El usuario
-- ────────────────────────────────────────────────────────────────────────────
-- `email_verified_at` va relleno a propósito: así la cuenta entra incluso con
-- AUTH_REQUIRE_VERIFIED_EMAIL=1, que es la verificación dura.
--
-- El hash es bcrypt (cost 12) de 'CambiaEsto1!'. Para usar OTRA contraseña,
-- genera el hash y sustitúyelo aquí:
--   php -r 'echo password_hash("TuClave1!", PASSWORD_DEFAULT), PHP_EOL;'
INSERT INTO auth.users (name, email, password, status, email_verified_at)
VALUES (
    'Administrador',
    'admin@example.com',
    '$2y$12$oKy.CIs5ZBPDCx4LTrzX0.vcOZgFwYZSToT/EBV2vLW8UShozoKUi',
    'A',
    now()
)
-- El índice único es sobre lower(email); el ON CONFLICT tiene que nombrar la
-- MISMA expresión para que Postgres lo reconozca.
ON CONFLICT (lower(email)) DO NOTHING;

-- ────────────────────────────────────────────────────────────────────────────
-- 2. Su rol
-- ────────────────────────────────────────────────────────────────────────────
-- `admin` y sus permisos los siembra auth_pgsql.sql. Aquí solo se enlazan.
INSERT INTO auth.user_roles (user_id, role_id)
SELECT u.id, r.id
  FROM auth.users u
  JOIN auth.roles r ON r.name = 'admin'
 WHERE lower(u.email) = lower('admin@example.com')
ON CONFLICT DO NOTHING;

-- ────────────────────────────────────────────────────────────────────────────
-- 3. Comprobación
-- ────────────────────────────────────────────────────────────────────────────
-- Debe devolver una fila con el rol 'admin' y sus permisos.
SELECT u.id,
       u.email,
       u.status,
       (u.email_verified_at IS NOT NULL)                    AS verificado,
       array_agg(DISTINCT r.name)                           AS roles,
       array_agg(DISTINCT p.name)                           AS permisos
  FROM auth.users u
  LEFT JOIN auth.user_roles       ur ON ur.user_id = u.id
  LEFT JOIN auth.roles            r  ON r.id  = ur.role_id
  LEFT JOIN auth.role_permissions rp ON rp.role_id = r.id
  LEFT JOIN auth.permissions      p  ON p.id  = rp.permission_id
 WHERE lower(u.email) = lower('admin@example.com')
 GROUP BY u.id, u.email, u.status, u.email_verified_at;

-- ============================================================================
-- RECETAS
-- ============================================================================
--
-- Cambiar la contraseña a mano (genera el hash con PHP, nunca la guardes en claro):
--
--   php -r 'echo password_hash("MiClaveNueva1!", PASSWORD_DEFAULT), PHP_EOL;'
--
--   UPDATE auth.users
--      SET password           = '<pega-aquí-el-hash>',
--          -- Invalida de golpe todas las sesiones abiertas con la clave vieja.
--          tokens_valid_after = extract(epoch FROM now())::bigint,
--          updated_at         = now()
--    WHERE lower(email) = lower('admin@example.com');
--
-- Convertir en admin a un usuario que se registró por su cuenta:
--
--   INSERT INTO auth.user_roles (user_id, role_id)
--   SELECT u.id, r.id FROM auth.users u JOIN auth.roles r ON r.name = 'admin'
--    WHERE lower(u.email) = lower('ada@example.com')
--   ON CONFLICT DO NOTHING;
--
-- Añadir un permiso propio y dárselo a un rol:
--
--   INSERT INTO auth.permissions (name, description)
--   VALUES ('reports:read', 'Ver informes') ON CONFLICT (name) DO NOTHING;
--
--   INSERT INTO auth.role_permissions (role_id, permission_id)
--   SELECT r.id, p.id FROM auth.roles r, auth.permissions p
--    WHERE r.name = 'admin' AND p.name = 'reports:read'
--   ON CONFLICT DO NOTHING;
--
-- Dar de baja una cuenta (el login la rechaza; los datos se conservan):
--
--   UPDATE auth.users SET status = 'I', tokens_valid_after = extract(epoch FROM now())::bigint
--    WHERE lower(email) = lower('ada@example.com');
-- ============================================================================
