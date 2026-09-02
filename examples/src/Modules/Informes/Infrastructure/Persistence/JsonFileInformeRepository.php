<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Infrastructure\Persistence;

use DateTimeImmutable;
use HexaLite\Examples\Modules\Informes\Domain\EstadoInforme;
use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;
use RuntimeException;

/**
 * ADAPTADOR DE SALIDA sobre un fichero JSON.
 *
 * Este es el TERCER adaptador del mismo puerto, y está aquí porque es el mejor
 * argumento que existe a favor de esta arquitectura: memoria, fichero y
 * PostgreSQL guardan los datos de tres maneras que no se parecen en nada, y ni
 * el dominio ni los casos de uso cambian una coma entre uno y otro. Cambias la
 * línea del provider y ya está.
 *
 * Es el adaptador por defecto del ejemplo para que puedas ver el flujo completo
 * —crear, listar, publicar, y el 409 al publicar dos veces— sin instalar nada.
 * No es para producción: bloquea el fichero entero en cada escritura.
 */
final readonly class JsonFileInformeRepository implements InformeRepositoryInterface
{
    public function __construct(
        private string $ruta,
    ) {}

    public function delProyecto(int $idProyecto): array
    {
        return array_values(array_filter(
            $this->leer(),
            static fn (Informe $i): bool => $i->idProyecto === $idProyecto,
        ));
    }

    public function porId(int $id): ?Informe
    {
        return $this->leer()[$id] ?? null;
    }

    public function guardar(Informe $informe): Informe
    {
        $informes = $this->leer();

        $id = $informe->id ?? (($informes === [] ? 0 : max(array_keys($informes))) + 1);

        $persistido = new Informe(
            $id,
            $informe->titulo,
            $informe->idProyecto,
            $informe->estado,
            $informe->publicadoEn,
        );

        $informes[$id] = $persistido;
        $this->escribir($informes);

        return $persistido;
    }

    /** @return array<int, Informe> */
    private function leer(): array
    {
        if (!is_file($this->ruta)) {
            return [];
        }

        $crudo = file_get_contents($this->ruta);

        if ($crudo === false || trim($crudo) === '') {
            return [];
        }

        /** @var array<int, array<string, mixed>> $filas */
        $filas    = json_decode($crudo, true, flags: JSON_THROW_ON_ERROR);
        $informes = [];

        foreach ($filas as $fila) {
            $id            = (int) $fila['id'];
            $publicadoEn   = $fila['publicado_en'] ?? null;
            $informes[$id] = new Informe(
                $id,
                (string) $fila['titulo'],
                (int) $fila['id_proyecto'],
                EstadoInforme::from((string) $fila['estado']),
                $publicadoEn === null ? null : new DateTimeImmutable((string) $publicadoEn),
            );
        }

        return $informes;
    }

    /** @param array<int, Informe> $informes */
    private function escribir(array $informes): void
    {
        $directorio = dirname($this->ruta);

        if (!is_dir($directorio) && !mkdir($directorio, 0o775, true) && !is_dir($directorio)) {
            throw new RuntimeException("No se pudo crear el directorio {$directorio}.");
        }

        $filas = array_map(static fn (Informe $i): array => [
            'id'           => $i->id,
            'titulo'       => $i->titulo,
            'id_proyecto'  => $i->idProyecto,
            'estado'       => $i->estado->value,
            'publicado_en' => $i->publicadoEn?->format('Y-m-d H:i:s'),
        ], array_values($informes));

        $json = json_encode($filas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->ruta, $json, LOCK_EX) === false) {
            throw new RuntimeException("No se pudo escribir en {$this->ruta}.");
        }
    }
}
