<?php
declare(strict_types=1);

namespace HexaLite\Logging;

use Psr\Log\LoggerInterface;
use Stringable;

class SimpleLogger implements LoggerInterface
{
    private string $logFile;

    public function __construct(string $logDir)
    {
        $this->logFile = rtrim($logDir, '/') . '/app.log';
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->write('EMERGENCY', (string) $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->write('ALERT', (string) $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->write('CRITICAL', (string) $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->write('ERROR', (string) $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->write('WARNING', (string) $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->write('NOTICE', (string) $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->write('INFO', (string) $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->write('DEBUG', (string) $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->write(strtoupper((string) $level), (string) $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // Evitar forja de líneas de log (log injection) con CR/LF en el mensaje.
        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $message);

        // Adjuntar el contexto estructurado (antes se descartaba por completo).
        $ctx = '';
        if ($context !== []) {
            $json = json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            $ctx = ' ' . ($json !== false ? $json : '[context no serializable]');
        }

        $line = "[$timestamp] [$level] {$message}{$ctx}" . PHP_EOL;

        // Intentar escribir al archivo, fallback a error_log
        if (@file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log("[$level] {$message}{$ctx}");
        }
    }
}
