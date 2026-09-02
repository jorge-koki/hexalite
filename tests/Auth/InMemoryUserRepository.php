<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\Domain\User;
use HexaLite\Auth\Domain\UserRepositoryInterface;

/**
 * Implementación en memoria de {@see UserRepositoryInterface} para los tests
 * funcionales: deja ejercitar el flujo HTTP completo sin una base de datos.
 *
 * Sirve además de ejemplo de lo pequeño que es el contrato que hay que cumplir
 * para enchufar el kit de auth a una tabla de usuarios que ya exista.
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $nextId = 1;

    /** @var array<string, string[]> Roles por nombre de usuario-id. */
    private array $rolesByUser = [];

    /** @var array<string, string[]> Permisos por nombre de rol. */
    private array $permissionsByRole = ['admin' => ['users:read', 'users:write'], 'user' => ['users:read']];

    // ── Lectura ──────────────────────────────────────────────────────────────

    public function findByEmail(string $email): ?User
    {
        foreach ($this->rows as $row) {
            if (strcasecmp((string) $row['email'], trim($email)) === 0) {
                return User::fromArray($row);
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return isset($this->rows[$id]) ? User::fromArray($this->rows[$id]) : null;
    }

    public function findByGoogleSub(string $googleSub): ?User
    {
        foreach ($this->rows as $row) {
            if (($row['google_sub'] ?? null) === $googleSub) {
                return User::fromArray($row);
            }
        }

        return null;
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function health(): array
    {
        return ['status' => 'ok'];
    }

    // ── Alta y credenciales ──────────────────────────────────────────────────

    public function create(
        string $name,
        string $email,
        ?string $passwordHash,
        ?string $verificationToken = null,
        bool $emailVerified = false,
        ?string $googleSub = null,
        array $roles = [],
    ): User {
        $id  = $this->nextId++;
        $now = gmdate('Y-m-d H:i:s');

        $this->rows[$id] = [
            'id'                        => $id,
            'name'                      => $name,
            'email'                     => strtolower(trim($email)),
            'password'                  => $passwordHash,
            'status'                    => 'A',
            'email_verified_at'         => $emailVerified ? $now : null,
            'email_verification_token'  => $verificationToken,
            'password_reset_token'      => null,
            'password_reset_expires_at' => null,
            'tokens_valid_after'        => 0,
            'google_sub'                => $googleSub,
            'last_login_at'             => null,
            'created_at'                => $now,
            'updated_at'                => $now,
        ];

        $this->rolesByUser[$id] = $roles;

        return User::fromArray($this->rows[$id]);
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        if (!isset($this->rows[$userId])) {
            return false;
        }
        $this->rows[$userId]['password']             = $passwordHash;
        $this->rows[$userId]['password_reset_token'] = null;

        return true;
    }

    public function updateProfile(int $userId, string $name): ?User
    {
        if (!isset($this->rows[$userId])) {
            return null;
        }
        $this->rows[$userId]['name'] = $name;

        return User::fromArray($this->rows[$userId]);
    }

    public function touchLastLogin(int $userId): void
    {
        if (isset($this->rows[$userId])) {
            $this->rows[$userId]['last_login_at'] = gmdate('Y-m-d H:i:s');
        }
    }

    // ── Verificación ─────────────────────────────────────────────────────────

    public function verifyEmailByToken(string $token): ?array
    {
        foreach ($this->rows as $id => $row) {
            if (($row['email_verification_token'] ?? null) === $token && $token !== '') {
                $this->rows[$id]['email_verified_at']        = gmdate('Y-m-d H:i:s');
                $this->rows[$id]['email_verification_token'] = null;

                return ['id' => $id, 'email' => (string) $row['email'], 'name' => (string) $row['name']];
            }
        }

        return null;
    }

    public function refreshVerificationToken(int $userId, string $token): ?array
    {
        if (!isset($this->rows[$userId])) {
            return null;
        }

        $alreadyVerified = $this->rows[$userId]['email_verified_at'] !== null;
        if (!$alreadyVerified) {
            $this->rows[$userId]['email_verification_token'] = $token;
        }

        return [
            'email'            => (string) $this->rows[$userId]['email'],
            'name'             => (string) $this->rows[$userId]['name'],
            'already_verified' => $alreadyVerified,
        ];
    }

    // ── Restablecimiento ─────────────────────────────────────────────────────

    public function createPasswordResetToken(int $userId, string $token, int $ttlMinutes): ?array
    {
        if (!isset($this->rows[$userId])) {
            return null;
        }

        $this->rows[$userId]['password_reset_token']      = $token;
        $this->rows[$userId]['password_reset_expires_at'] = time() + $ttlMinutes * 60;

        return [
            'email' => (string) $this->rows[$userId]['email'],
            'name'  => (string) $this->rows[$userId]['name'],
        ];
    }

    public function resetPasswordByToken(string $token, string $passwordHash): ?array
    {
        foreach ($this->rows as $id => $row) {
            $matches = $token !== ''
                && ($row['password_reset_token'] ?? null) === $token
                && (int) ($row['password_reset_expires_at'] ?? 0) > time();

            if ($matches) {
                $this->rows[$id]['password']                 = $passwordHash;
                $this->rows[$id]['password_reset_token']     = null;
                $this->rows[$id]['password_reset_expires_at'] = null;

                return ['id' => $id, 'email' => (string) $row['email'], 'name' => (string) $row['name']];
            }
        }

        return null;
    }

    // ── Revocación ───────────────────────────────────────────────────────────

    public function getTokensValidAfter(int $userId): int
    {
        return (int) ($this->rows[$userId]['tokens_valid_after'] ?? 0);
    }

    public function setTokensValidAfter(int $userId, int $epoch): void
    {
        if (isset($this->rows[$userId])) {
            $this->rows[$userId]['tokens_valid_after'] = $epoch;
        }
    }

    // ── Google ───────────────────────────────────────────────────────────────

    public function linkGoogle(int $userId, string $googleSub, bool $markEmailVerified): bool
    {
        foreach ($this->rows as $id => $row) {
            if ($id !== $userId && ($row['google_sub'] ?? null) === $googleSub) {
                return false;
            }
        }

        $this->rows[$userId]['google_sub'] = $googleSub;
        if ($markEmailVerified) {
            $this->rows[$userId]['email_verified_at'] ??= gmdate('Y-m-d H:i:s');
        }

        return true;
    }

    public function unlinkGoogle(int $userId): void
    {
        $this->rows[$userId]['google_sub'] = null;
    }

    // ── Roles y permisos ─────────────────────────────────────────────────────

    public function getRoleNames(int $userId): array
    {
        return $this->rolesByUser[$userId] ?? [];
    }

    public function getEffectivePermissions(int $userId): array
    {
        $permissions = [];
        foreach ($this->getRoleNames($userId) as $role) {
            $permissions = [...$permissions, ...($this->permissionsByRole[$role] ?? [])];
        }

        return array_values(array_unique($permissions));
    }
}
