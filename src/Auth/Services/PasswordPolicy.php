<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Http\HttpException;

/**
 * Política de contraseñas del kit, en UN solo sitio para que el registro, el
 * reset y el cambio no puedan divergir (que es como acaban colándose
 * contraseñas que un flujo acepta y otro no).
 *
 * Por defecto: 8+ caracteres con minúscula, MAYÚSCULA, número y un carácter
 * especial. Se puede relajar/endurecer con variables de entorno.
 */
final class PasswordPolicy
{
    public function __construct(
        private readonly int $minLength = 8,
        private readonly int $maxLength = 250,
        private readonly bool $requireMixedCase = true,
        private readonly bool $requireNumber = true,
        private readonly bool $requireSymbol = true,
    ) {
    }

    /**
     * Lee la política del entorno:
     *   PASSWORD_MIN_LENGTH, PASSWORD_MAX_LENGTH,
     *   PASSWORD_REQUIRE_MIXED_CASE, PASSWORD_REQUIRE_NUMBER, PASSWORD_REQUIRE_SYMBOL
     */
    public static function fromEnv(): self
    {
        $flag = static function (string $key, bool $default): bool {
            $value = getenv($key);
            if ($value === false || trim((string) $value) === '') {
                return $default;
            }
            return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
        };

        return new self(
            minLength:        (int) (getenv('PASSWORD_MIN_LENGTH') ?: 8),
            maxLength:        (int) (getenv('PASSWORD_MAX_LENGTH') ?: 250),
            requireMixedCase: $flag('PASSWORD_REQUIRE_MIXED_CASE', true),
            requireNumber:    $flag('PASSWORD_REQUIRE_NUMBER', true),
            requireSymbol:    $flag('PASSWORD_REQUIRE_SYMBOL', true),
        );
    }

    /** @return string|null Mensaje del primer incumplimiento, o null si pasa. */
    public function check(string $password): ?string
    {
        // mb_strlen: una contraseña con acentos o emoji no debe contar bytes.
        $length = mb_strlen($password);

        if ($length < $this->minLength) {
            return "La contraseña debe tener al menos {$this->minLength} caracteres.";
        }
        if ($length > $this->maxLength) {
            return "La contraseña no puede superar los {$this->maxLength} caracteres.";
        }
        if ($this->requireMixedCase && !(preg_match('/[a-z]/u', $password) && preg_match('/[A-Z]/u', $password))) {
            return 'La contraseña debe incluir mayúsculas y minúsculas.';
        }
        if ($this->requireNumber && !preg_match('/\d/u', $password)) {
            return 'La contraseña debe incluir al menos un número.';
        }
        if ($this->requireSymbol && !preg_match('/[^\p{L}\p{N}]/u', $password)) {
            return 'La contraseña debe incluir al menos un carácter especial.';
        }

        return null;
    }

    public function passes(string $password): bool
    {
        return $this->check($password) === null;
    }

    /** @throws HttpException 422 con el motivo si la contraseña no cumple. */
    public function assert(string $password): void
    {
        $error = $this->check($password);
        if ($error !== null) {
            throw new HttpException('weak_password', $error, 422);
        }
    }

    /**
     * Hash de la contraseña. Se usa el algoritmo por defecto de PHP (hoy bcrypt,
     * mañana lo que PHP considere mejor) para no quedarse anclado a uno concreto.
     */
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** Expresión regular equivalente, para validar también en los DTOs y el front. */
    public function toRegex(): string
    {
        $lookaheads = '';
        if ($this->requireMixedCase) {
            $lookaheads .= '(?=.*[a-z])(?=.*[A-Z])';
        }
        if ($this->requireNumber) {
            $lookaheads .= '(?=.*\d)';
        }
        if ($this->requireSymbol) {
            $lookaheads .= '(?=.*[\W_])';
        }

        return $lookaheads . '.{' . $this->minLength . ',}';
    }
}
