<?php

declare(strict_types=1);

namespace HexaLite\Auth\Services;

use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Cache\CacheInterface;

/**
 * Revocación de sesiones por "marca de agua" (`tokens_valid_after`).
 *
 * Un JWT es válido solo si su `iat` es >= la marca del usuario. Al cambiar o
 * restablecer la contraseña —o al cerrar sesión— la marca se pone en `now()`, y
 * TODOS los tokens emitidos antes mueren al instante. Es la forma barata de
 * revocar sin llevar una lista negra de `jti`: una comparación de enteros.
 *
 * La BD es la fuente de verdad; el caché (Redis, si está) evita pegarle a la BD
 * en cada petición autenticada. Sin caché funciona igual, solo con una consulta
 * más por request.
 */
final class TokenRevocationService
{
    /** Vida de la entrada en caché. Corta a propósito: una revocación debe propagarse rápido. */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /** ¿Sigue vigente un token emitido en `$issuedAt` para este usuario? */
    public function isTokenValid(int $userId, int $issuedAt): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $validAfter = $this->validAfter($userId);

        return $validAfter === 0 || $issuedAt >= $validAfter;
    }

    /**
     * Revoca TODAS las sesiones del usuario y devuelve la marca fijada.
     *
     * La marca es `time() + 1`, no `time()`: el `iat` de un JWT tiene resolución
     * de SEGUNDOS, así que con `time()` un token emitido en el mismo segundo que
     * el logout sobreviviría a la revocación. Con +1 muere todo lo emitido hasta
     * este segundo inclusive.
     *
     * Quien necesite seguir dentro después de revocar —el dispositivo desde el
     * que se cambió la contraseña— debe acuñar sus tokens nuevos con este valor
     * como `issuedAt`; por eso se devuelve.
     *
     * @return int Epoch de la marca de revocación.
     */
    public function invalidateUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $validAfter = time() + 1;

        $this->users->setTokensValidAfter($userId, $validAfter);
        // Se escribe el valor nuevo (no solo se invalida) para que la siguiente
        // lectura sea coherente aunque venga de otro worker.
        $this->cache?->set($this->key($userId), (string) $validAfter, self::CACHE_TTL);

        return $validAfter;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Marca de revocación en epoch (0 = el usuario nunca revocó). */
    private function validAfter(int $userId): int
    {
        $cached = $this->cache?->get($this->key($userId));
        if ($cached !== null) {
            return (int) $cached;
        }

        $value = $this->users->getTokensValidAfter($userId);
        $this->cache?->set($this->key($userId), (string) $value, self::CACHE_TTL);

        return $value;
    }

    private function key(int $userId): string
    {
        return "auth:tva:$userId";
    }
}
