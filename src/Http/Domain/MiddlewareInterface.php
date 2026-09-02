<?php
declare(strict_types=1);

namespace HexaLite\Http\Domain;

use HexaLite\Http\Request;
use HexaLite\Http\Response;

interface MiddlewareInterface
{
    /**
     * Maneja la petición y decide si pasa al siguiente middleware/controlador
     * 
     * @param Request $request Petición HTTP
     * @param callable $next Siguiente middleware o controlador
     * @return Response
     */
    public function handle(Request $request, callable $next): Response;
}
