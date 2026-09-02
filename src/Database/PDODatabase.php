<?php

declare(strict_types=1);

namespace HexaLite\Database;

use PDO;
use PDOException;

/**
 * Implementación Síncrona de Base de Datos usando PDO
 */
class PDODatabase implements DatabaseInterface
{
    private ?PDO $pdo = null;

    /**
     * @param string $dsn Cadena de conexión DSN de PDO (ej: mysql:host=127.0.0.1;dbname=app)
     * @param string $user Usuario
     * @param string $password Contraseña
     * @param array $options Opciones adicionales para PDO
     */
    public function __construct(
        string $dsn,
        string $user,
        string $password,
        array $options = []
    ) {
        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Reintentos automáticos de conexión a nivel driver si se dropea
            PDO::ATTR_PERSISTENT         => false, // Sin conexiones persistentes; PHP-FPM gestiona el pool por worker
        ];

        $this->pdo = new PDO($dsn, $user, $password, array_replace($defaultOptions, $options));
    }

    /**
     * Accesor interno con guarda: garantiza que la conexión no esté cerrada.
     */
    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('La conexión a la base de datos está cerrada.');
        }
        return $this->pdo;
    }

    /**
     * Ejecuta una consulta SQL preparadas y devuelve un objeto de resultado
     *
     * @param string $sql
     * @param array $params
     * @return QueryResult
     */
    public function query(string $sql, array $params = []): QueryResult
    {
        try {
            // Convierte parámetros nombrados o mezclados si es necesario.
            // PDO ya soporta $params nombrados nativamente (:nombre) o indexados (?)
            // pero para ser compatible con la antigua interfaz, dejaremos que PDO lo maneje.
            
            $stmt = $this->pdo()->prepare($sql);
            
            // Si $params es un array secuencial, PDO espera que los binds sean 1-indexed 
            // en execute, o se pasan directo al execute.
            $stmt->execute($params);

            return new QueryResult($stmt);

        } catch (PDOException $e) {
            throw new \RuntimeException("Database Query Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Obtiene el ID del último registro insertado
     */
    public function lastInsertId(): string|false
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Ejecuta un callback dentro de una transacción. Si falla, hace rollback automático.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Devuelve la instancia nativa de PDO por si se requieren características avanzadas
     */
    public function getPDO(): PDO
    {
        return $this->pdo();
    }

    /**
     * Cierra explícitamente la conexión.
     *
     * Soltar la referencia al objeto PDO basta para que PHP cierre el socket
     * subyacente. Antes se asignaba `new PDO('sqlite::memory:')` como placeholder,
     * lo que exigía la extensión pdo_sqlite (no siempre instalada → fatal).
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    public function __destruct()
    {
        // $this->close();
    }
}
