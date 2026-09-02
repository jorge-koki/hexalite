<?php

declare(strict_types=1);

namespace HexaLite\Auth\Domain;

use DateTimeImmutable;

/**
 * Usuario autenticable. Modelo de dominio del starter kit de autenticación:
 * los campos son los que exige el flujo (identidad, credencial, estado y
 * verificación) y nada más. Todo lo específico de tu negocio —empresa, plan,
 * avatar, preferencias— vive en TUS tablas, no aquí.
 */
final readonly class User
{
    /**
     * @param string|null $password  Hash bcrypt/argon. NULL = la cuenta solo entra
     *                               por un proveedor externo (Google) y no tiene contraseña.
     * @param string      $status    'A' activa | 'I' inactiva (dada de baja).
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public ?string $password = null,
        public string $status = 'A',
        public ?DateTimeImmutable $email_verified_at = null,
        public ?DateTimeImmutable $last_login_at = null,
        public ?string $google_sub = null,
        public ?DateTimeImmutable $created_at = null,
        public ?DateTimeImmutable $updated_at = null,
    ) {
    }

    /** Hidrata desde una fila de la BD (claves = nombres de columna). */
    public static function fromArray(array $row): self
    {
        $date = static fn(mixed $v): ?DateTimeImmutable => (is_string($v) && $v !== '')
            ? new DateTimeImmutable($v)
            : null;

        return new self(
            id:                isset($row['id']) ? (int) $row['id'] : null,
            name:              (string) ($row['name'] ?? ''),
            email:             (string) ($row['email'] ?? ''),
            password:          $row['password'] ?? null,
            status:            (string) ($row['status'] ?? 'A'),
            email_verified_at: $date($row['email_verified_at'] ?? null),
            last_login_at:     $date($row['last_login_at'] ?? null),
            google_sub:        $row['google_sub'] ?? null,
            created_at:        $date($row['created_at'] ?? null),
            updated_at:        $date($row['updated_at'] ?? null),
        );
    }

    /**
     * Representación pública del usuario. NUNCA incluye el hash de la contraseña:
     * este es el array que acaba en el JSON de /auth/me y /auth/login.
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'status'         => $this->status,
            'email_verified' => $this->hasVerifiedEmail(),
            'has_password'   => $this->password !== null,
            'google_linked'  => $this->google_sub !== null,
            'last_login_at'  => $this->last_login_at?->format(DATE_ATOM),
            'created_at'     => $this->created_at?->format(DATE_ATOM),
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'A';
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Comprueba la contraseña en claro contra el hash guardado.
     *
     * Una cuenta SIN contraseña (solo Google) devuelve false, no una excepción:
     * así el login la trata como cualquier credencial incorrecta y la respuesta
     * no delata qué tipo de cuenta es.
     */
    public function verifyPassword(string $plain): bool
    {
        return $this->password !== null && password_verify($plain, $this->password);
    }
}
