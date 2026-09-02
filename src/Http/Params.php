<?php
declare(strict_types=1);

namespace HexaLite\Http;

class Params
{
    public function __construct(
        private array $routeParams = [],
        private array $queryParams = []
    ) {}

    public function __get(string $key): mixed
    {
        return $this->routeParams[$key] ?? $this->queryParams[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->routeParams[$key]) || isset($this->queryParams[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function allRoute(): array
    {
        return $this->routeParams;
    }

    public function allQuery(): array
    {
        return $this->queryParams;
    }

    public function all(): array
    {
        return array_merge($this->queryParams, $this->routeParams);
    }

    public function int(string $key, int $default = 0): int
    {
        $val = $this->get($key);
        return $val !== null ? (int) $val : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $val = $this->get($key);
        return $val !== null ? (string) $val : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $val = $this->get($key);
        if ($val === null) return $default;
        if (\is_bool($val)) return $val;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $val = $this->get($key);
        return $val !== null ? (float) $val : $default;
    }
}
