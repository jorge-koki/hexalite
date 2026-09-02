<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Infrastructure\Persistence;

use DateTimeImmutable;
use HexaLite\Database\DatabaseInterface;
use HexaLite\Examples\Modules\Informes\Domain\EstadoInforme;
use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;

/**
 * ADAPTADOR DE SALIDA sobre PDO. Aquí —y SOLO aquí— vive el SQL.
 *
 * Este es el sitio donde el modelo de la base de datos y el modelo del negocio se
 * traducen mutuamente. `hidratar()` es esa frontera: convierte una fila plana en
 * una entidad con sus tipos de verdad (`EstadoInforme`, `DateTimeImmutable`), y
 * corta en seco que los `string` del driver se filtren hacia adentro.
 *
 * Esquema en `migrations/informes_pgsql.sql` y `migrations/informes_mysql.sql`.
 */
final readonly class PdoInformeRepository implements InformeRepositoryInterface
{
    private const COLUMNAS = 'id, titulo, id_proyecto, estado, publicado_en';

    public function __construct(
        private DatabaseInterface $db,
    ) {}

    public function delProyecto(int $idProyecto): array
    {
        $filas = $this->db->query(
            'SELECT ' . self::COLUMNAS . ' FROM informes WHERE id_proyecto = ? ORDER BY id',
            [$idProyecto],
        )->fetchAll();

        return array_map($this->hidratar(...), $filas);
    }

    public function porId(int $id): ?Informe
    {
        $fila = $this->db->query(
            'SELECT ' . self::COLUMNAS . ' FROM informes WHERE id = ?',
            [$id],
        )->fetch();

        return $fila === null ? null : $this->hidratar($fila);
    }

    public function guardar(Informe $informe): Informe
    {
        return $informe->id === null
            ? $this->insertar($informe)
            : $this->actualizar($informe);
    }

    private function insertar(Informe $informe): Informe
    {
        $this->db->query(
            'INSERT INTO informes (titulo, id_proyecto, estado, publicado_en) VALUES (?, ?, ?, ?)',
            [
                $informe->titulo,
                $informe->idProyecto,
                $informe->estado->value,
                $informe->publicadoEn?->format('Y-m-d H:i:s'),
            ],
        );

        // Portable entre MySQL y PostgreSQL mientras `id` sea autoincremental:
        // en pgsql, PDO resuelve esto con `lastval()` sobre la secuencia.
        $id = (int) $this->db->lastInsertId();

        return new Informe(
            $id,
            $informe->titulo,
            $informe->idProyecto,
            $informe->estado,
            $informe->publicadoEn,
        );
    }

    private function actualizar(Informe $informe): Informe
    {
        $this->db->query(
            'UPDATE informes SET titulo = ?, id_proyecto = ?, estado = ?, publicado_en = ? WHERE id = ?',
            [
                $informe->titulo,
                $informe->idProyecto,
                $informe->estado->value,
                $informe->publicadoEn?->format('Y-m-d H:i:s'),
                $informe->id,
            ],
        );

        return $informe;
    }

    /**
     * Frontera de traducción: fila de la base de datos → entidad del dominio.
     *
     * @param array<string, mixed> $fila
     */
    private function hidratar(array $fila): Informe
    {
        $publicadoEn = $fila['publicado_en'] ?? null;

        return new Informe(
            (int) $fila['id'],
            (string) $fila['titulo'],
            (int) $fila['id_proyecto'],
            EstadoInforme::from((string) $fila['estado']),
            $publicadoEn === null ? null : new DateTimeImmutable((string) $publicadoEn),
        );
    }
}
