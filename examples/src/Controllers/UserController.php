<?php

declare(strict_types=1);

namespace HexaLite\Examples\Controllers;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Route;
use HexaLite\Http\HttpException;
use HexaLite\Http\Response;
use HexaLite\Examples\Dtos\CreateUserDto;
use HexaLite\Examples\Services\UserRepository;

#[Controller('/users')]
final class UserController
{
    // Autowiring: el contenedor construye e inyecta UserRepository (singleton).
    public function __construct(private UserRepository $users) {}

    #[Route('', method: 'GET')]
    public function list(): Response
    {
        return response(['data' => $this->users->all()]);
    }

    #[Route('/{id}', method: 'GET')]
    public function show(int $id): Response
    {
        $user = $this->users->find($id);

        if ($user === null) {
            // El Router traduce HttpException a la Response JSON con su status.
            throw new HttpException('USER_NOT_FOUND', "No existe el usuario {$id}.", 404);
        }

        return response(['data' => $user]);
    }

    #[Route('', method: 'POST')]
    public function create(CreateUserDto $dto): Response
    {
        // Si el body no cumple las reglas del DTO, el Router responde 422 solo.
        $user = $this->users->create($dto->toArray());

        return response(['data' => $user], 201);
    }
}
