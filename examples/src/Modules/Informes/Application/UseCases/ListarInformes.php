<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Application\UseCases;

use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;

/**
 * Un caso de uso = una intención del negocio = un método público.
 *
 * Mira el tipo del constructor: la INTERFAZ, nunca la implementación concreta.
 * Ese detalle de una línea es lo que permite probar este caso de uso en
 * microsegundos con el repositorio en memoria, sin levantar una base de datos.
 */
final readonly class ListarInformes
{
    public function __construct(
        private InformeRepositoryInterface $informes,
    ) {}

    /** @return list<Informe> */
    public function execute(int $idProyecto): array
    {
        return $this->informes->delProyecto($idProyecto);
    }
}
