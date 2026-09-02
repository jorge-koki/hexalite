<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Domain;

use DateTimeImmutable;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeYaPublicado;

/**
 * ENTIDAD DEL DOMINIO — el centro del hexágono.
 *
 * Fíjate primero en lo que NO hay aquí: ni SQL, ni Request, ni Response, ni
 * atributos de validación HTTP, ni un solo `use` que apunte a Infrastructure. El
 * dominio no sabe que existe una base de datos ni que lo están llamando por HTTP.
 * Por eso se prueba sin levantar absolutamente nada.
 *
 * Y tampoco es una bolsa de getters. `publicar()` es una regla de negocio, y vive
 * pegada a los datos que protege: un informe no se puede publicar dos veces, y
 * ese invariante se cumple AQUÍ. Si lo pusieras en el controlador, se te escapa
 * por la siguiente puerta que abras — un cron, una cola, un comando de consola.
 */
final readonly class Informe
{
    public function __construct(
        public ?int $id,
        public string $titulo,
        public int $idProyecto,
        public EstadoInforme $estado = EstadoInforme::Borrador,
        public ?DateTimeImmutable $publicadoEn = null,
    ) {}

    /** Un informe recién nacido todavía no tiene id: se lo asigna la persistencia. */
    public static function nuevo(string $titulo, int $idProyecto): self
    {
        return new self(null, $titulo, $idProyecto);
    }

    /**
     * Devuelve el informe publicado. Al ser `readonly` no mutamos: construimos el
     * siguiente estado. Así nadie puede dejar la entidad a medio camino entre dos
     * estados válidos.
     */
    public function publicar(DateTimeImmutable $cuando): self
    {
        if ($this->estado === EstadoInforme::Publicado) {
            throw InformeYaPublicado::conId((int) $this->id);
        }

        return new self(
            $this->id,
            $this->titulo,
            $this->idProyecto,
            EstadoInforme::Publicado,
            $cuando,
        );
    }

    public function estaPublicado(): bool
    {
        return $this->estado === EstadoInforme::Publicado;
    }
}
