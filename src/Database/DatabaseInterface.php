<?php

declare(strict_types=1);

namespace HexaLite\Database;

/**
 * Contrato para las implementaciones de base de datos síncronas usando PDO
 */
interface DatabaseInterface
{
    /**
     * Ejecuta una query y retorna un QueryResult
     */
    public function query(string $sql, array $params = []): QueryResult;

    /**
     * Obtiene el último ID insertado
     */
    public function lastInsertId(): string|false;

    /**
     * Ejecuta una operación atómica usando una conexión reservada.
     * 
     * @param callable $callback Función que recibe el ejecutor de la transacción
     * @return mixed El resultado del callback
     */
    public function transaction(callable $callback): mixed;

    /**
     * Cierra las conexiones
     */
    public function close(): void;
}