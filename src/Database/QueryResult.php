<?php

declare(strict_types=1);

namespace HexaLite\Database;

use ArrayIterator;
use PDOStatement;
use IteratorAggregate;
use Traversable;

/**
 * Wrapper para PDOStatement para mantener la interfaz de respuesta de BD.
 */
class QueryResult implements IteratorAggregate
{
    private PDOStatement $result;

    /** Buffer perezoso de filas: hace fetchAll()/iteración repetibles y consistentes. */
    private ?array $rows = null;

    public function __construct(PDOStatement $result)
    {
        $this->result = $result;
    }

    /**
     * Obtiene la siguiente fila o null si no hay más resultados.
     * (Streaming: avanza el cursor del statement.)
     */
    public function fetch(): array|null
    {
        $row = $this->result->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Obtiene todas las filas como array. El resultado se cachea, por lo que es
     * seguro llamarlo varias veces (antes el cursor forward-only de PDO se
     * "consumía" y una segunda lectura/iteración devolvía vacío).
     */
    public function fetchAll(): array
    {
        return $this->rows ??= $this->result->fetchAll();
    }

    /**
     * Obtiene el número de filas afectadas
     */
    public function rowCount(): int
    {
        return $this->result->rowCount();
    }

    /**
     * Permite iterar sobre los resultados en un foreach de forma repetible.
     * Itera sobre el buffer de fetchAll() en lugar del cursor forward-only de PDO,
     * de modo que `foreach` tras un fetchAll() (o dos foreach seguidos) funciona.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->fetchAll());
    }
}
