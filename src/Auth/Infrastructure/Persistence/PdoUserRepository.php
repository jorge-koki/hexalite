<?php

declare(strict_types=1);

namespace HexaLite\Auth\Infrastructure\Persistence;

use HexaLite\Auth\Domain\User;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Database\DatabaseInterface;
use Throwable;

/**
 * Persistencia del kit de auth sobre PostgreSQL o MySQL con UNA sola clase.
 *
 * Las dos diferencias reales entre motores están aisladas:
 *   • Nombres de tabla — PostgreSQL usa el esquema `auth.users`; MySQL no tiene
 *     esquemas dentro de una base, así que ahí las tablas son `auth_users`.
 *   • Recuperar el id de un INSERT — `RETURNING` en PostgreSQL, `lastInsertId()`
 *     en MySQL.
 *
 * Todo lo demás se escribió a propósito en SQL que ambos entienden: las marcas
 * de tiempo usan `CURRENT_TIMESTAMP` y las expiraciones se calculan en PHP y
 * viajan como parámetro, en vez de usar la aritmética de intervalos (que sí
 * difiere entre motores).
 */
final class PdoUserRepository implements UserRepositoryInterface
{
    private string $users;
    private string $roles;
    private string $permissions;
    private string $userRoles;
    private string $rolePermissions;

    private bool $supportsReturning;

    /**
     * @param string $driver 'pgsql' | 'mysql'.
     * @param string|null $tablePrefix Fuerza el prefijo de las tablas. Por defecto
     *        'auth.' en PostgreSQL y 'auth_' en MySQL, que es lo que crean las
     *        migraciones incluidas.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        string $driver = 'pgsql',
        ?string $tablePrefix = null,
    ) {
        $prefix = $tablePrefix ?? ($driver === 'mysql' ? 'auth_' : 'auth.');

        $this->users           = $prefix . 'users';
        $this->roles           = $prefix . 'roles';
        $this->permissions     = $prefix . 'permissions';
        $this->userRoles       = $prefix . 'user_roles';
        $this->rolePermissions = $prefix . 'role_permissions';

        $this->supportsReturning = $driver === 'pgsql';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LECTURA
    // ─────────────────────────────────────────────────────────────────────────

    public function findByEmail(string $email): ?User
    {
        // LOWER en ambos lados: los correos son insensibles a mayúsculas en la
        // práctica, y así el login no falla por cómo se tecleó al registrarse.
        $row = $this->db->query(
            "SELECT * FROM {$this->users} WHERE LOWER(email) = LOWER(?)",
            [trim($email)]
        )->fetch();

        return $row ? User::fromArray($row) : null;
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->query("SELECT * FROM {$this->users} WHERE id = ?", [$id])->fetch();

        return $row ? User::fromArray($row) : null;
    }

    public function findByGoogleSub(string $googleSub): ?User
    {
        $googleSub = trim($googleSub);
        if ($googleSub === '') {
            return null;
        }

        $row = $this->db->query("SELECT * FROM {$this->users} WHERE google_sub = ?", [$googleSub])->fetch();

        return $row ? User::fromArray($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $row = $this->db->query(
            "SELECT 1 AS found FROM {$this->users} WHERE LOWER(email) = LOWER(?) LIMIT 1",
            [trim($email)]
        )->fetch();

        return $row !== null;
    }

    public function health(): array
    {
        try {
            $this->db->query('SELECT 1');
            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALTA Y CREDENCIALES
    // ─────────────────────────────────────────────────────────────────────────

    public function create(
        string $name,
        string $email,
        ?string $passwordHash,
        ?string $verificationToken = null,
        bool $emailVerified = false,
        ?string $googleSub = null,
        array $roles = [],
    ): User {
        return $this->db->transaction(function (DatabaseInterface $db) use (
            $name,
            $email,
            $passwordHash,
            $verificationToken,
            $emailVerified,
            $googleSub,
            $roles
        ): User {
            $now = self::now();

            $columns = [
                'name'                       => $name,
                'email'                      => strtolower(trim($email)),
                'password'                   => $passwordHash,
                'status'                     => 'A',
                'email_verified_at'          => $emailVerified ? $now : null,
                'email_verification_token'   => $verificationToken,
                'email_verification_sent_at' => $verificationToken !== null ? $now : null,
                'google_sub'                 => $googleSub,
                'google_linked_at'           => $googleSub !== null ? $now : null,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ];

            $names        = implode(', ', array_keys($columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $values       = array_values($columns);

            if ($this->supportsReturning) {
                $row = $db->query(
                    "INSERT INTO {$this->users} ($names) VALUES ($placeholders) RETURNING *",
                    $values
                )->fetch();
                $userId = (int) $row['id'];
            } else {
                $db->query("INSERT INTO {$this->users} ($names) VALUES ($placeholders)", $values);
                $userId = (int) $db->lastInsertId();
                $row    = $db->query("SELECT * FROM {$this->users} WHERE id = ?", [$userId])->fetch();
            }

            $this->assignRoles($db, $userId, $roles);

            return User::fromArray($row);
        });
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $result = $this->db->query(
            "UPDATE {$this->users}
                SET password = ?, updated_at = CURRENT_TIMESTAMP,
                    password_reset_token = NULL, password_reset_expires_at = NULL
              WHERE id = ?",
            [$passwordHash, $userId]
        );

        return $result->rowCount() > 0;
    }

    public function updateProfile(int $userId, string $name): ?User
    {
        $this->db->query(
            "UPDATE {$this->users} SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [trim($name), $userId]
        );

        return $this->findById($userId);
    }

    public function touchLastLogin(int $userId): void
    {
        $this->db->query(
            "UPDATE {$this->users} SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$userId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFICACIÓN DE CORREO
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyEmailByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->db->query(
            "SELECT id, email, name FROM {$this->users} WHERE email_verification_token = ?",
            [$token]
        )->fetch();

        if ($row === null) {
            return null;
        }

        // Se limpia el token en el mismo paso: single-use. `COALESCE` conserva la
        // fecha original si el usuario ya estaba verificado (idempotente ante
        // reintentos del enlace).
        $this->db->query(
            "UPDATE {$this->users}
                SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP),
                    email_verification_token = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [(int) $row['id']]
        );

        return ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name']];
    }

    public function refreshVerificationToken(int $userId, string $token): ?array
    {
        $row = $this->db->query(
            "SELECT email, name, email_verified_at FROM {$this->users} WHERE id = ?",
            [$userId]
        )->fetch();

        if ($row === null) {
            return null;
        }

        $alreadyVerified = ($row['email_verified_at'] ?? null) !== null;

        if (!$alreadyVerified) {
            $this->db->query(
                "UPDATE {$this->users}
                    SET email_verification_token = ?,
                        email_verification_sent_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?",
                [$token, $userId]
            );
        }

        return [
            'email'            => (string) $row['email'],
            'name'             => (string) $row['name'],
            'already_verified' => $alreadyVerified,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESTABLECIMIENTO DE CONTRASEÑA
    // ─────────────────────────────────────────────────────────────────────────

    public function createPasswordResetToken(int $userId, string $token, int $ttlMinutes): ?array
    {
        $row = $this->db->query(
            "SELECT email, name FROM {$this->users} WHERE id = ?",
            [$userId]
        )->fetch();

        if ($row === null) {
            return null;
        }

        // La expiración se calcula en PHP: la aritmética de intervalos es lo
        // único que MySQL y PostgreSQL escriben distinto en todo este repositorio.
        $this->db->query(
            "UPDATE {$this->users}
                SET password_reset_token = ?,
                    password_reset_sent_at = CURRENT_TIMESTAMP,
                    password_reset_expires_at = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [$token, self::now($ttlMinutes * 60), $userId]
        );

        return ['email' => (string) $row['email'], 'name' => (string) $row['name']];
    }

    public function resetPasswordByToken(string $token, string $passwordHash): ?array
    {
        if ($token === '') {
            return null;
        }

        // La vigencia se comprueba en el WHERE, no antes en PHP: así no hay
        // ventana entre "compruebo" y "aplico" en la que el token pudiera
        // consumirse dos veces.
        $row = $this->db->query(
            "SELECT id, email, name FROM {$this->users}
              WHERE password_reset_token = ? AND password_reset_expires_at > CURRENT_TIMESTAMP",
            [$token]
        )->fetch();

        if ($row === null) {
            return null;
        }

        $updated = $this->db->query(
            "UPDATE {$this->users}
                SET password = ?,
                    password_reset_token = NULL,
                    password_reset_expires_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND password_reset_token = ?",
            [$passwordHash, (int) $row['id'], $token]
        );

        // Si otra petición consumió el token entre el SELECT y el UPDATE, aquí
        // no se actualiza nada y se responde "token inválido", que es lo correcto.
        if ($updated->rowCount() === 0) {
            return null;
        }

        return ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name']];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REVOCACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function getTokensValidAfter(int $userId): int
    {
        $row = $this->db->query(
            "SELECT tokens_valid_after FROM {$this->users} WHERE id = ?",
            [$userId]
        )->fetch();

        return (int) ($row['tokens_valid_after'] ?? 0);
    }

    public function setTokensValidAfter(int $userId, int $epoch): void
    {
        $this->db->query(
            "UPDATE {$this->users} SET tokens_valid_after = ? WHERE id = ?",
            [$epoch, $userId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GOOGLE
    // ─────────────────────────────────────────────────────────────────────────

    public function linkGoogle(int $userId, string $googleSub, bool $markEmailVerified): bool
    {
        // El índice único parcial es la garantía dura; este pre-chequeo solo sirve
        // para poder responder algo entendible en vez de un error de la BD.
        $taken = $this->db->query(
            "SELECT id FROM {$this->users} WHERE google_sub = ? AND id <> ?",
            [$googleSub, $userId]
        )->fetch();

        if ($taken !== null) {
            return false;
        }

        $this->db->query(
            "UPDATE {$this->users}
                SET google_sub = ?,
                    google_linked_at = COALESCE(google_linked_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [$googleSub, $userId]
        );

        if ($markEmailVerified) {
            $this->db->query(
                "UPDATE {$this->users}
                    SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP),
                        email_verification_token = NULL
                  WHERE id = ?",
                [$userId]
            );
        }

        return true;
    }

    public function unlinkGoogle(int $userId): void
    {
        $this->db->query(
            "UPDATE {$this->users}
                SET google_sub = NULL, google_linked_at = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [$userId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROLES Y PERMISOS
    // ─────────────────────────────────────────────────────────────────────────

    public function getRoleNames(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT r.name
               FROM {$this->userRoles} ur
               JOIN {$this->roles} r ON r.id = ur.role_id
              WHERE ur.user_id = ?
              ORDER BY r.name",
            [$userId]
        )->fetchAll();

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    public function getEffectivePermissions(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT p.name
               FROM {$this->userRoles} ur
               JOIN {$this->rolePermissions} rp ON rp.role_id = ur.role_id
               JOIN {$this->permissions} p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
              ORDER BY p.name",
            [$userId]
        )->fetchAll();

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Asigna roles por NOMBRE. Los que no existan se ignoran en silencio: un rol
     * mal escrito en la configuración no debe impedir que alguien se registre —
     * se quedaría sin permisos, que es el fallo seguro.
     *
     * @param string[] $roles
     */
    private function assignRoles(DatabaseInterface $db, int $userId, array $roles): void
    {
        foreach ($roles as $roleName) {
            $role = $db->query("SELECT id FROM {$this->roles} WHERE name = ?", [$roleName])->fetch();
            if ($role === null) {
                continue;
            }

            $db->query(
                "INSERT INTO {$this->userRoles} (user_id, role_id) VALUES (?, ?)",
                [$userId, (int) $role['id']]
            );
        }
    }

    /** Marca de tiempo UTC en el formato que aceptan ambos motores. */
    private static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}
