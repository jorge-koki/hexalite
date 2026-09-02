<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Application\UseCases;

use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;

/**
 * La capa de aplicación ORQUESTA, no decide: le pide al dominio que construya y
 * al puerto que guarde. No conoce HTTP (eso es del controlador) y no inventa
 * reglas (eso es del dominio).
 *
 * Recibe valores sueltos, no el DTO. A propósito: el DTO es un objeto de la capa
 * HTTP, y si el caso de uso lo tipara en su firma, dejaría de poder invocarse
 * desde un comando de consola o desde un test sin fabricar una petición falsa.
 */
final readonly class CrearInforme
{
    public function __construct(
        private InformeRepositoryInterface $informes,
    ) {}

    public function execute(string $titulo, int $idProyecto): Informe
    {
        return $this->informes->guardar(Informe::nuevo($titulo, $idProyecto));
    }
}
