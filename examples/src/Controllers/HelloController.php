<?php

declare(strict_types=1);

namespace HexaLite\Examples\Controllers;

use HexaLite\Attributes\Route;
use HexaLite\Http\Response;

final class HelloController
{
    #[Route('/', method: 'GET')]
    public function index(): Response
    {
        return response([
            'message'   => 'Hello from HexaLite 🚀',
            'framework' => 'hexalite/framework',
        ]);
    }

    // El parámetro dinámico {name} se inyecta tipado como string.
    #[Route('/hello/{name}', method: 'GET')]
    public function greet(string $name): Response
    {
        return response(['message' => "¡Hola, {$name}!"]);
    }
}
