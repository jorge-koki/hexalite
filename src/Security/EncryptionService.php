<?php

declare(strict_types=1);

namespace HexaLite\Security;

use RuntimeException;
use SodiumException;

/**
 * Cifrado simétrico autenticado (XSalsa20-Poly1305) vía libsodium, para guardar
 * secretos EN REPOSO: el App Password del SMTP del usuario, el token de una
 * integración de terceros, el número de cuenta que la ley obliga a no dejar en
 * claro. No es para contraseñas de acceso — esas se hashean con
 * {@see \HexaLite\Auth\Services\PasswordPolicy}, no se cifran.
 *
 * "Autenticado" significa que un texto cifrado manipulado no se descifra a
 * basura: falla, y `decrypt()` devuelve null. Nunca devuelve datos alterados.
 *
 * La clave se DERIVA del secreto con BLAKE2b a 32 bytes, así que cualquier
 * cadena sirve como `APP_ENCRYPTION_KEY` (no hace falta que mida exactamente
 * 32 bytes) y el secreto original nunca se usa tal cual.
 *
 * OJO: dentro de un mismo entorno la clave NO puede cambiar mientras existan
 * datos cifrados con ella, o dejarían de poder descifrarse. Para rotarla hay que
 * descifrar con la vieja y volver a cifrar con la nueva.
 *
 *   $enc = new EncryptionService();            // clave desde el entorno
 *   $row = $enc->encrypt($appPassword);        // guardar esto en la BD
 *   $pwd = $enc->decrypt($row) ?? throw new RuntimeException('secreto corrupto');
 */
final class EncryptionService
{
    private string $key;

    /**
     * @param string|null $secret Secreto del que derivar la clave. Si es null se lee
     *                            de `APP_ENCRYPTION_KEY` y, como respaldo, de `JWT_SECRET`.
     *
     * @throws RuntimeException Si falta `ext-sodium` o no hay ningún secreto configurado.
     */
    public function __construct(?string $secret = null)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(
                'HexaLite\Security\EncryptionService necesita la extensión sodium (ext-sodium), '
                . 'incluida en PHP desde 7.2. Habilítala para cifrar secretos en reposo.'
            );
        }

        $secret ??= self::env('APP_ENCRYPTION_KEY') ?? self::env('JWT_SECRET');

        if ($secret === null || $secret === '') {
            throw new RuntimeException(
                'Falta APP_ENCRYPTION_KEY (o JWT_SECRET como respaldo) para cifrar secretos en reposo. '
                . 'Genera una con: php -r \'echo bin2hex(random_bytes(32));\''
            );
        }

        $this->key = sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Cifra texto plano → base64(nonce . ciphertext).
     *
     * El nonce es aleatorio en cada llamada, así que cifrar dos veces el mismo
     * texto da resultados distintos: no se puede deducir por igualdad de columnas
     * que dos usuarios comparten el mismo secreto.
     */
    public function encrypt(string $plain): string
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    /**
     * Descifra base64(nonce . ciphertext) → texto plano.
     *
     * Devuelve null —nunca lanza— si el dato no es base64 válido, viene truncado,
     * fue manipulado o se cifró con OTRA clave. Quien llama decide si eso es un
     * error fatal o un secreto que hay que volver a pedir al usuario.
     */
    public function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        if ($raw === false || strlen($raw) < $min) {
            return null;
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        } catch (SodiumException) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }
}
