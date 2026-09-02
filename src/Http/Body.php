<?php
declare(strict_types=1);

namespace HexaLite\Http;

use HexaLite\Http\ValidationException;

class Body
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function __get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Verifica que existan las claves requeridas, lanza ValidationException si faltan
     *
     * @throws ValidationException
     */
    public function validated(array $requiredKeys): array
    {
        $errors = [];
        foreach ($requiredKeys as $key) {
            if (!isset($this->data[$key]) || $this->data[$key] === '') {
                $errors[$key] = ["El campo '{$key}' es obligatorio."];
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $this->only($requiredKeys);
    }
}
