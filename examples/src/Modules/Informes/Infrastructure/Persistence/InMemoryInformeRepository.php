<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Infrastructure\Persistence;

use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;

/**
 * ADAPTADOR DE SALIDA en memoria.
 *
 * Existe por dos razones, y las dos importan:
 *
 *   1. el ejemplo arranca sin base de datos, y
 *   2. demuestra el pago de la arquitectura: implementa el mismo puerto que el
 *      adaptador de PostgreSQL, así que los casos de uso funcionan con este sin
 *      cambiar UNA sola línea. Eso es exactamente lo que haces en un test.
 *
 * Recuerda que PHP-FPM es share-nothing: este array nace vacío en cada petición.
 * Sirve para probar y para tests; para persistir de verdad está el adaptador PDO.
 */
final class InMemoryInformeRepository implements InformeRepositoryInterface
{
    /** @var array<int, Informe> */
    private array $informes = [];

    private int $siguienteId = 1;

    /** @param list<Informe> $iniciales Semilla opcional, útil para los tests. */
    public function __construct(array $iniciales = [])
    {
        foreach ($iniciales as $informe) {
            $this->guardar($informe);
        }
    }

    public function delProyecto(int $idProyecto): array
    {
        return array_values(array_filter(
            $this->informes,
            static fn (Informe $i): bool => $i->idProyecto === $idProyecto,
        ));
    }

    public function porId(int $id): ?Informe
    {
        return $this->informes[$id] ?? null;
    }

    public function guardar(Informe $informe): Informe
    {
        $id = $informe->id ?? $this->siguienteId++;

        $persistido = new Informe(
            $id,
            $informe->titulo,
            $informe->idProyecto,
            $informe->estado,
            $informe->publicadoEn,
        );

        $this->informes[$id] = $persistido;

        return $persistido;
    }
}
