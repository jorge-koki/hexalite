<?php

declare(strict_types=1);

namespace HexaLite\Auth\Domain;

/**
 * Puerto de persistencia del módulo de autenticación.
 *
 * El framework trae {@see \HexaLite\Auth\Infrastructure\Persistence\PdoUserRepository},
 * que implementa esto sobre PostgreSQL o MySQL con el esquema de
 * `src/Auth/migrations/`. Si tu tabla de usuarios ya existe y es distinta,
 * implementa esta interfaz contra ELLA y enlázala en el contenedor: el resto del
 * módulo (casos de uso, controlador, middlewares) sigue funcionando sin tocarlo.
 */
interface UserRepositoryInterface
{
    // ── Lectura ──────────────────────────────────────────────────────────────

    /** Búsqueda por correo, insensible a mayúsculas. */
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /** Usuario por el identificador estable de Google (claim `sub` del ID token). */
    public function findByGoogleSub(string $googleSub): ?User;

    public function emailExists(string $email): bool;

    /** Ping de conectividad para el endpoint de health. */
    public function health(): array;

    // ── Alta y credenciales ──────────────────────────────────────────────────

    /**
     * Crea el usuario y devuelve la fila ya persistida.
     *
     * @param string|null $passwordHash      NULL para cuentas que solo entran con Google.
     * @param string|null $verificationToken Token del correo de verificación (NULL = nace verificado).
     * @param bool        $emailVerified     true marca `email_verified_at` en el alta.
     * @param string[]    $roles             Nombres de rol a asignar (los que no existan se ignoran).
     */
    public function create(
        string $name,
        string $email,
        ?string $passwordHash,
        ?string $verificationToken = null,
        bool $emailVerified = false,
        ?string $googleSub = null,
        array $roles = [],
    ): User;

    /** Fija un hash de contraseña nuevo. Devuelve false si el usuario no existe. */
    public function updatePassword(int $userId, string $passwordHash): bool;

    /** Actualiza el perfil editable por el propio usuario. */
    public function updateProfile(int $userId, string $name): ?User;

    /** Sella `last_login_at = ahora`. Best-effort: nunca debe tumbar el login. */
    public function touchLastLogin(int $userId): void;

    // ── Verificación de correo ───────────────────────────────────────────────

    /**
     * Marca el correo como verificado a partir del token (single-use: el token se
     * limpia al consumirlo).
     *
     * @return array{id: int, email: string, name: string}|null null si el token no existe.
     */
    public function verifyEmailByToken(string $token): ?array;

    /**
     * Asigna un token de verificación nuevo (reenvío).
     *
     * @return array{email: string, name: string, already_verified: bool}|null null si el usuario no existe.
     */
    public function refreshVerificationToken(int $userId, string $token): ?array;

    // ── Restablecimiento de contraseña ───────────────────────────────────────

    /**
     * Genera/renueva el token de restablecimiento con expiración.
     *
     * @return array{email: string, name: string}|null null si el usuario no existe.
     */
    public function createPasswordResetToken(int $userId, string $token, int $ttlMinutes): ?array;

    /**
     * Aplica la contraseña nueva a partir del token: solo si existe y NO expiró.
     * El token es single-use (se limpia al aplicarlo).
     *
     * @return array{id: int, email: string, name: string}|null null si el token era inválido o venció.
     */
    public function resetPasswordByToken(string $token, string $passwordHash): ?array;

    // ── Revocación de sesiones ───────────────────────────────────────────────

    /** Epoch de la marca `tokens_valid_after` del usuario (0 = sin marca). */
    public function getTokensValidAfter(int $userId): int;

    /** Fija la marca de revocación: invalida de golpe todos los tokens anteriores. */
    public function setTokensValidAfter(int $userId, int $epoch): void;

    // ── Google ───────────────────────────────────────────────────────────────

    /**
     * Enlaza una cuenta de Google al usuario.
     *
     * @param bool $markEmailVerified Da por verificado el correo (solo cuando el correo
     *                                de Google es el MISMO del usuario: Google ya lo comprobó).
     * @return bool false si ese Google ya está enlazado a OTRO usuario.
     */
    public function linkGoogle(int $userId, string $googleSub, bool $markEmailVerified): bool;

    /** Quita el vínculo con Google (no toca la contraseña ni la verificación). */
    public function unlinkGoogle(int $userId): void;

    // ── Roles y permisos ─────────────────────────────────────────────────────

    /** @return string[] Nombres de los roles del usuario. */
    public function getRoleNames(int $userId): array;

    /** @return string[] Permisos efectivos (unión de los de todos sus roles). */
    public function getEffectivePermissions(int $userId): array;
}
