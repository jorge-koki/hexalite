<?php

namespace HexaLite\Http;

use HexaLite\Container\Container;
use HexaLite\Validation\Validator;
use HexaLite\Http\ValidationException as HttpValidationException;

class Request
{
    private array $attributes = [];
    private ?string $parsedPath = null;

    # Contenedor opcional para resolver servicios (injection friendly)
    private ?Container $container = null;

    # Proxies de confianza - solo confiar en headers X-Forwarded-For si viene de estos IPs
    private static array $trustedProxies = [];

    private array $cookies;

    # Usamos readonly en las propiedades, no en la clase entera
    public function __construct(
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        public readonly array $files,
        ?Container $container = null
    ) {
        $this->container = $container;

        # PHP puebla $_COOKIE automáticamente
        $this->cookies = $_COOKIE;
    }

    /**
     * Configura los proxies de confianza (llamar en bootstrap/index.php)
     *
     * @param array $proxies Lista de IPs o rangos CIDR (ej: ['127.0.0.1', '10.0.0.0/8'])
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    # Obtiene los proxies de confianza configurados
    public static function getTrustedProxies(): array
    {
        return self::$trustedProxies;
    }

    public static function createFromGlobals(?Container $container = null): self
    {
        # Detectar si es JSON y procesarlo UNA SOLA VEZ aquí
        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            // php://input es un stream wrapper de PHP, NO un archivo del filesystem.
            // file_get_contents es la forma correcta de leer el body crudo.
            $input = file_get_contents('php://input');
            if (\is_string($input) && $input !== '') {
                // Límite de profundidad defensivo. Solo aceptamos objetos/arreglos JSON
                // como body: un JSON escalar (5, "x", true) o inválido decodifica a
                // no-array y, si lo dejáramos pasar, el constructor `array $body`
                // lanzaría un TypeError (500). Lo normalizamos a [] para que la
                // validación responda 422 de forma limpia.
                $decoded = json_decode($input, true, 64);
                $body = \is_array($decoded) ? $decoded : [];
            } else {
                $body = [];
            }
        }

        return new self($_GET, $body, $_SERVER, $_FILES, $container);
    }


    # Obtiene un valor del body o del query string (en ese orden)
    public function input(string $key, mixed $default = null): mixed
    {
        # Prioridad: 1. Body (POST/JSON), 2. Query (GET), 3. Default
        $val = $this->body[$key] ?? $this->query[$key] ?? $default;
        return \is_string($val) ? trim($val) : $val;
    }

    /**
     * Devuelve TODOS los datos de entrada (Query + Body)
     * Ideal para pasar al validador.
     */
    public function all(): array
    {
        return [...$this->query, ...$this->body];
    }

    public function getMethod(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    public function getPath(): string
    {
        return $this->parsedPath ??= parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    }

    /**
     * Obtiene la IP del cliente de forma segura
     * Solo confía en headers de proxy si la petición viene de un proxy conocido
     */
    public function getIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';

        # Solo confiar en headers de proxy si viene de un proxy de confianza
        if ($this->isFromTrustedProxy($remoteAddr)) {
            # X-Forwarded-For puede tener múltiples IPs: "client, proxy1, proxy2"
            if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
                $ips = array_map('trim', explode(',', $this->server['HTTP_X_FORWARDED_FOR']));
                # Recorrer de derecha a izquierda, buscando la primera IP no confiable
                for ($i = \count($ips) - 1; $i >= 0; $i--) {
                    if (!$this->isFromTrustedProxy($ips[$i])) {
                        return $ips[$i];
                    }
                }
                # Si todas son de confianza, usar la primera
                return $ips[0];
            }

            if (!empty($this->server['HTTP_X_REAL_IP'])) {
                return $this->server['HTTP_X_REAL_IP'];
            }
        }

        return $remoteAddr;
    }

    /**
     * Verifica si una IP es de un proxy de confianza
     */
    private function isFromTrustedProxy(string $ip): bool
    {
        if (empty(self::$trustedProxies)) {
            return false;
        }

        foreach (self::$trustedProxies as $trusted) {
            if ($this->ipInRange($ip, $trusted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si una IP está dentro de un rango CIDR o es igual a una IP específica
     */
    private function ipInRange(string $ip, string $range): bool
    {
        # Si no tiene /, es una IP exacta
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        # Rango CIDR
        [$subnet, $bits] = explode('/', $range);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Contenedor DI asociado a la petición (si se creó con uno).
     * Útil para middlewares/guards que necesiten resolver servicios.
     */
    public function getContainer(): ?Container
    {
        return $this->container;
    }

    /**
     * Usuario autenticado de la petición.
     *
     * HexaLite es agnóstico al esquema de autenticación: el core NO decodifica
     * tokens. El patrón recomendado es que un middleware de auth valide la
     * credencial (JWT en cookie/Bearer, sesión, API key…) y publique el usuario
     * resuelto con `$request->setAttribute('user', $user)`. Este helper solo lo
     * lee de vuelta.
     *
     * @return mixed  El usuario publicado por el middleware, o null si no hay sesión.
     */
    public function user(): mixed
    {
        return $this->getAttribute('user');
    }

    /**
     * Obtiene el JWT ya sea de la Cookie (Prioridad) o del Header (Fallback).
     * Solo EXTRAE el token crudo; no lo valida ni lo decodifica (eso es tarea
     * del middleware de auth de la aplicación).
     */
    public function getJwtToken(): ?string
    {
        # Intentar leer de la Cookie HttpOnly
        if (!empty($this->cookies['access_token'])) {
            return $this->cookies['access_token'];
        }

        # Fallback: Intentar leer Bearer token (Para Apps móviles o Postman)
        return $this->getBearerToken();
    }

    /**
     * Obtiene un header HTTP
     *
     * @param string $name Nombre del header (ej: 'Authorization', 'Content-Type')
     * @return string|null Valor del header o null si no existe
     */
    public function getHeader(string $name): ?string
    {
        # Convertir nombre del header al formato SERVER
        # Authorization -> HTTP_AUTHORIZATION
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    /**
     * Obtiene el token Bearer del header Authorization
     *
     * @return string|null Token sin el prefijo "Bearer " o null si no existe
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization') ?? '';

        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    public function getFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    public function hasFiles(): bool
    {
        foreach ($this->files as $file) {
            if (isset($file['error']) && $file['error'] === UPLOAD_ERR_OK) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string>                       $rulesMap       ['campo' => 'regla1|regla2:param']
     * @param array<string, array<string, string>>        $customMessages ['campo' => ['nombreRegla' => 'mensaje']]
     */
    public function validate(array $rulesMap, array $customMessages = []): array
    {
        # Usamos all() para validar tanto parámetros GET como datos POST/JSON
        $data = $this->all();

        $validator = new Validator($data);
        $errors = $validator->validate($rulesMap, $customMessages);

        if (!empty($errors)) {
            throw new HttpValidationException($errors);
        }

        # Retornar solo los datos validados y limpios
        return array_intersect_key($data, $rulesMap);
    }

    /**
     * Obtiene una cookie específica que vino en la petición
     */
    public function cookie(string $key, $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Verifica si existe una cookie
     */
    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }
}
