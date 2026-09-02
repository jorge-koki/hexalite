<?php

declare(strict_types=1);

namespace HexaLite\Examples\Services;

/**
 * Repositorio en memoria (solo para el ejemplo). No tiene dependencias en el
 * constructor, así que el contenedor lo resuelve por autowiring como singleton.
 */
final class UserRepository
{
    /** @var array<int, array{id:int, name:string, email:string}> */
    private array $users = [
        1 => ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        2 => ['id' => 2, 'name' => 'Alan Turing', 'email' => 'alan@example.com'],
    ];

    private int $nextId = 3;

    /** @return list<array{id:int, name:string, email:string}> */
    public function all(): array
    {
        return array_values($this->users);
    }

    /** @return array{id:int, name:string, email:string}|null */
    public function find(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }

    /**
     * @param  array{name:string, email:string} $data
     * @return array{id:int, name:string, email:string}
     */
    public function create(array $data): array
    {
        $id = $this->nextId++;
        $user = ['id' => $id, 'name' => $data['name'], 'email' => $data['email']];
        $this->users[$id] = $user;

        return $user;
    }
}
