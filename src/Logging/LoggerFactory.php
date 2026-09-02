<?php
declare(strict_types=1);

namespace HexaLite\Logging;

use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

class LoggerFactory
{
    /**
     * Crea un logger PSR-3.
     *
     * Si `monolog/monolog` está instalado, devuelve un Monolog con un StreamHandler
     * hacia `{$logDir}/app.log`. Si no, cae al {@see SimpleLogger} incluido (cero
     * dependencias). El directorio de logs se pasa por parámetro — el core NO asume
     * ninguna constante global ni layout de carpetas de la aplicación.
     *
     * @param string $logDir  Directorio donde se escribirán los logs. Se crea si no existe.
     * @param string $channel Nombre del canal Monolog (ignorado por SimpleLogger).
     */
    public static function create(string $logDir, string $channel = 'app'): LoggerInterface
    {
        $logDir = rtrim($logDir, '/');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Si Monolog está instalado, úsalo
        if (class_exists(Logger::class) && class_exists(StreamHandler::class)) {
            $logger = new Logger($channel);
            $logger->pushHandler(new StreamHandler($logDir . '/app.log', Level::Debug));
            return $logger;
        }

        // Si no, usa el logger simple (sin dependencias)
        return new SimpleLogger($logDir);
    }
}
