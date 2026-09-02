<?php

declare(strict_types=1);

/**
 * Helpers globales de HexaLite.
 *
 * Se cargan vía el autoload `files` de Composer. Solo contiene utilidades
 * AGNÓSTICAS a la aplicación; el formato de moneda, fechas localizadas y demás
 * helpers de negocio pertenecen a tu app, no al framework.
 *
 * Cada función está protegida con function_exists() para que la app pueda
 * redefinirla (o para convivir con otros paquetes que declaren el mismo nombre).
 */

use HexaLite\Http\Response;
use HexaLite\Http\Router;
use HexaLite\Http\ValidationException;
use HexaLite\ResponseFactory;
use Psr\Log\LoggerInterface;

if (!function_exists('response')) {
    /**
     * Fábrica de respuestas.
     *
     *   return response(['ok' => true]);          // 200 JSON
     *   return response($data, 201);              // 201 JSON
     *   return response()->withCookie('t', $v);   // proxy fluido
     *
     * @return Response|ResponseFactory
     */
    function response(mixed $data = null, int $status = 200): Response|ResponseFactory
    {
        // Sin argumentos → proxy fluido para encadenar métodos.
        if (func_num_args() === 0 || $data === null) {
            return new ResponseFactory();
        }
        return Response::json($data, $status);
    }
}

if (!function_exists('env')) {
    /**
     * Lee una variable de entorno con valor por defecto y casteo de literales.
     * Convierte "true"/"false"/"null"/"empty" a sus valores PHP.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('loadEnv')) {
    /**
     * Carga variables de entorno desde un archivo .env (parser mínimo, sin dependencias).
     *
     * @param string $filePath Ruta al archivo .env
     */
    function loadEnv(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            # Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            # Separar nombre y valor
            if (strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            # Eliminar espacios y comillas envolventes
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($name !== '') {
                putenv("$name=$value");
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('url')) {
    /**
     * Genera una URL absoluta para un path de la aplicación.
     *
     * Orden de resolución (seguro por defecto, inmune a Host header injection):
     *   1) APP_URL configurado  → base canónica (recomendado para emails/enlaces).
     *   2) Host de la request SANITIZADO, restringido a APP_TRUSTED_HOSTS si existe.
     *   3) Sin nada confiable   → ruta relativa.
     */
    function url(string $path): string
    {
        $path = ltrim($path, '/');

        $router  = Router::getInstance();
        $baseUrl = $router ? $router->getBaseUrl() : '';

        # 1) URL canónica configurada.
        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
        if (is_string($appUrl) && $appUrl !== '') {
            return rtrim($appUrl, '/') . "{$baseUrl}/{$path}";
        }

        # 2) Host de la request, sanitizado y (si se define) restringido a una allowlist.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        # Solo caracteres válidos de host[:puerto]; corta CRLF / inyección de cabeceras.
        if (!preg_match('/^[A-Za-z0-9.\-:]+$/', (string) $host)) {
            $host = '';
        }

        $trusted = array_filter(array_map('trim', explode(',', (string) (getenv('APP_TRUSTED_HOSTS') ?: ''))));
        if ($host !== '' && ($trusted === [] || in_array($host, $trusted, true))) {
            return "{$protocol}://{$host}{$baseUrl}/{$path}";
        }

        # 3) Degradar a ruta relativa (seguro por defecto).
        return "{$baseUrl}/{$path}";
    }
}

if (!function_exists('console_log')) {
    /**
     * Escribe una línea en el log de errores de PHP (el de PHP-FPM, o stderr con
     * el servidor embebido). Existe para depurar sin ensuciar la respuesta: en
     * una API JSON un `echo` rompe el cuerpo, `console_log()` no.
     *
     * Para el log de la aplicación usa el logger PSR-3; esto es para el rastro
     * rápido durante el desarrollo.
     */
    function console_log(string $message): void
    {
        error_log($message);
    }
}

if (!function_exists('reportError')) {
    /**
     * Registra una excepción con el contexto útil ya extraído.
     *
     * De una ValidationException saca el detalle campo por campo (`errors`), que
     * es lo único que sirve para entender un 422; del resto, el mensaje. Se
     * registra como `warning` y con la CLASE de la excepción como texto, así el
     * log agrupa por tipo de fallo en vez de por mensaje irrepetible.
     *
     * Sin logger cae al log de PHP, para que un error nunca desaparezca solo
     * porque el contenedor todavía no tenía un logger que dar.
     *
     * @param LoggerInterface|null $logger Logger PSR-3, o null.
     * @param mixed                $e      Throwable, o cualquier valor serializable.
     */
    function reportError(?LoggerInterface $logger, mixed $e): void
    {
        $errors = null;

        if ($e instanceof ValidationException || (is_object($e) && property_exists($e, 'errors'))) {
            $errors = $e->errors ?? null;
        }

        $context = $errors !== null
            ? ['errors' => $errors]
            : ['message' => $e instanceof Throwable ? $e->getMessage() : json_encode($e)];

        if ($e instanceof Throwable) {
            $context['at'] = $e->getFile() . ':' . $e->getLine();
        }

        if ($logger !== null) {
            $logger->warning($e instanceof Throwable ? get_class($e) : 'Error', $context);
            return;
        }

        error_log((string) json_encode($context));
    }
}
