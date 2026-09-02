<?php

declare(strict_types=1);

namespace HexaLite\Auth\Attributes;

use Attribute;
use HexaLite\Auth\Guards\ThrottleGuard;

/**
 * Limita las peticiones por IP+ruta.
 *
 *   #[Throttle(5, 60)]   // 5 peticiones por minuto
 *
 * Registra el atributo en el Router (parámetro `guardAttributes`) para que lo
 * reconozca. El propio atributo declara qué Guard lo ejecuta.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Throttle
{
    public string $guardClass;

    /**
     * @param int $limit    Máximo de peticiones dentro de la ventana.
     * @param int $ttl      Duración de la ventana, en segundos.
     * @param int $priority Orden de ejecución entre guards (menor = antes).
     */
    public function __construct(
        public int $limit = 60,
        public int $ttl = 60,
        public int $priority = 5,
    ) {
        $this->guardClass = ThrottleGuard::class;
    }
}
