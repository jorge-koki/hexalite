<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Http\HttpException;
use Throwable;

/**
 * Verifica el ID token de «Continuar con Google» — sin librerías de JWT: la
 * firma RS256 se comprueba con `openssl_verify` contra las llaves públicas que
 * Google publica en su JWKS.
 *
 * Verificar de verdad son cinco cosas, y las cinco importan:
 *   1. FIRMA válida contra el JWKS de Google. Sin esto, cualquiera fabrica un
 *      token diciendo ser quien quiera.
 *   2. `aud` == NUESTRO client ID. Si no, vale un token emitido para otra app:
 *      un atacante podría reusar el de cualquier sitio donde el usuario entró.
 *   3. `iss` de Google.
 *   4. `exp` vigente.
 *   5. `email_verified` == true. Google también emite tokens de cuentas con el
 *      correo sin confirmar; sin este chequeo alguien reclama un correo ajeno.
 *
 * Nunca se confía en lo que mande el navegador (correo, nombre): todo sale del
 * token ya verificado.
 */
final class GoogleIdTokenService
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS   = ['https://accounts.google.com', 'accounts.google.com'];

    /** Las llaves de Google rotan cada pocos días; una hora de caché sobra. */
    private const CACHE_TTL = 3600;

    /** Tolerancia de reloj al comprobar `exp`. */
    private const LEEWAY = 60;

    /**
     * @param string      $clientId Client ID de OAuth (GOOGLE_CLIENT_ID). Es público.
     * @param string|null $cacheDir Dónde guardar el JWKS. null = directorio temporal del sistema.
     */
    public function __construct(
        private readonly string $clientId = '',
        private readonly ?string $cacheDir = null,
    ) {
    }

    /** Sin client ID el botón de Google no debería ni ofrecerse. */
    public function isConfigured(): bool
    {
        return $this->resolveClientId() !== '';
    }

    /**
     * @return array{sub: string, email: string, name: string, picture: ?string}
     * @throws HttpException si el token no sirve (nunca devuelve datos a medias).
     */
    public function verify(string $idToken): array
    {
        $clientId = $this->resolveClientId();
        if ($clientId === '') {
            throw new HttpException(
                'google_not_configured',
                'El inicio de sesión con Google no está configurado.',
                503
            );
        }
        if (!extension_loaded('openssl')) {
            throw new HttpException(
                'google_not_configured',
                'El inicio de sesión con Google necesita la extensión ext-openssl.',
                503
            );
        }

        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new HttpException('google_token_missing', 'Falta el token de Google.', 422);
        }

        $payload = $this->decodeVerified($idToken);

        // 2) Audiencia: hash_equals evita filtrar el client ID por tiempos (aunque
        //    sea público, comparar secretos y cuasi-secretos así es la costumbre sana).
        if (!hash_equals($clientId, (string) ($payload['aud'] ?? ''))) {
            error_log('[google-auth] aud inesperado: ' . (string) ($payload['aud'] ?? ''));
            throw self::rejected();
        }

        // 3) Emisor.
        if (!in_array((string) ($payload['iss'] ?? ''), self::ISSUERS, true)) {
            throw self::rejected();
        }

        // 5) Correo confirmado por Google (el claim llega como bool o como "true").
        $verified = $payload['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new HttpException(
                'google_email_unverified',
                'Tu cuenta de Google no tiene el correo verificado. Verifícalo con Google e intenta de nuevo.',
                403
            );
        }

        $sub   = trim((string) ($payload['sub'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($sub === '' || $email === '') {
            throw self::rejected();
        }

        return [
            'sub'     => $sub,
            'email'   => $email,
            'name'    => trim((string) ($payload['name'] ?? '')) ?: $email,
            'picture' => trim((string) ($payload['picture'] ?? '')) ?: null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFICACIÓN DE LA FIRMA (RS256)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> Claims con la firma y el `exp` ya comprobados. */
    private function decodeVerified(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw self::rejected();
        }
        [$header64, $payload64, $signature64] = $parts;

        $header  = json_decode((string) self::b64UrlDecode($header64), true);
        $payload = json_decode((string) self::b64UrlDecode($payload64), true);
        if (!is_array($header) || !is_array($payload)) {
            throw self::rejected();
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            // Solo RS256: aceptar otro algoritmo abre el ataque de degradar la firma.
            throw self::rejected();
        }

        $jwk = $this->findKey((string) ($header['kid'] ?? ''));
        if ($jwk === null) {
            throw self::rejected();
        }

        $ok = openssl_verify(
            "$header64.$payload64",
            self::b64UrlDecode($signature64),
            self::jwkToPem($jwk['n'], $jwk['e']),
            OPENSSL_ALGO_SHA256,
        );
        if ($ok !== 1) {
            throw self::rejected();
        }

        if (!isset($payload['exp']) || time() - self::LEEWAY >= (int) $payload['exp']) {
            throw self::rejected();
        }

        return $payload;
    }

    /**
     * Llave del JWKS por `kid`. Si no aparece, se fuerza una recarga: puede ser
     * simplemente que Google acabe de rotar las llaves y tengamos caché vieja.
     *
     * @return array{n: string, e: string}|null
     */
    private function findKey(string $kid): ?array
    {
        foreach ([false, true] as $forceRefresh) {
            foreach ($this->fetchCerts($forceRefresh)['keys'] ?? [] as $key) {
                if (($key['kid'] ?? '') === $kid && isset($key['n'], $key['e'])) {
                    return ['n' => (string) $key['n'], 'e' => (string) $key['e']];
                }
            }
        }

        return null;
    }

    /**
     * JWKS de Google, cacheado en disco. Si la descarga falla pero hay caché
     * —aunque esté vencida— se usa esa: mejor eso que dejar sin login a todo el
     * mundo por un hipo de red.
     *
     * @return array<string, mixed>
     */
    private function fetchCerts(bool $forceRefresh = false): array
    {
        $cacheFile = rtrim($this->cacheDir ?? sys_get_temp_dir(), '/') . '/hexalite_google_jwks.json';

        if (!$forceRefresh && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) {
                return $cached;
            }
        }

        $response = $this->httpGet(self::CERTS_URL);
        $decoded  = is_string($response) ? json_decode($response, true) : null;

        if (!is_array($decoded) || !isset($decoded['keys'])) {
            if (is_file($cacheFile)) {
                $stale = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($stale) && isset($stale['keys'])) {
                    error_log('[google-auth] JWKS no disponible, usando caché vencida.');
                    return $stale;
                }
            }
            throw new HttpException(
                'google_unreachable',
                'No pude conectar con Google. Intenta de nuevo en un momento.',
                503
            );
        }

        @file_put_contents($cacheFile, (string) $response, LOCK_EX);

        return $decoded;
    }

    private function httpGet(string $url): ?string
    {
        try {
            if (extension_loaded('curl')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
                return is_string($body) ? $body : null;
            }

            $context = stream_context_create(['http' => ['timeout' => 10]]);
            $body    = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JWK (n, e) → PEM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convierte el módulo y el exponente de un JWK RSA en una clave pública PEM,
     * que es lo único que entiende `openssl_verify`. Se construye el DER a mano:
     *
     *   SubjectPublicKeyInfo ::= SEQUENCE {
     *       algorithm  SEQUENCE { OID rsaEncryption, NULL },
     *       publicKey  BIT STRING { SEQUENCE { INTEGER n, INTEGER e } }
     *   }
     */
    private static function jwkToPem(string $n, string $e): string
    {
        $rsaPublicKey = self::derSequence(
            self::derInteger(self::b64UrlDecode($n))
            . self::derInteger(self::b64UrlDecode($e))
        );

        // OID 1.2.840.113549.1.1.1 (rsaEncryption) + parámetros NULL.
        $algorithm = self::derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");

        $spki = self::derSequence($algorithm . self::derBitString($rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** Longitud DER: forma corta (<128) o larga. */
    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // Un byte inicial >= 0x80 se leería como número NEGATIVO en DER: se antepone
        // un 0x00 para que el entero siga siendo positivo.
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derBitString(string $contents): string
    {
        // El primer byte de un BIT STRING indica los bits sin usar del último byte.
        $contents = "\x00" . $contents;

        return "\x03" . self::derLength(strlen($contents)) . $contents;
    }

    private static function b64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un solo mensaje para TODOS los motivos de rechazo (firma, aud, iss, exp…).
     * El detalle va al log del servidor: decirle al cliente cuál falló solo le
     * sirve a quien está probando tokens.
     */
    private static function rejected(): HttpException
    {
        return new HttpException(
            'google_token_invalid',
            'No se pudo validar tu cuenta de Google. Intenta de nuevo.',
            401
        );
    }

    private function resolveClientId(): string
    {
        if ($this->clientId !== '') {
            return $this->clientId;
        }
        $env = getenv('GOOGLE_CLIENT_ID');

        return $env === false ? '' : trim($env);
    }
}
