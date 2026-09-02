<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Application\UseCases;

use DateTimeImmutable;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeNoEncontrado;
use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;

/**
 * El caso de uso más interesante de los tres, porque enseña dónde va cada cosa:
 *
 *   1. busca (puerto),
 *   2. deja que la ENTIDAD aplique la regla —`publicar()` es quien decide si se
 *      puede o no—,
 *   3. persiste el resultado (puerto).
 *
 * Fíjate en que aquí NO hay un `if ($informe->estado === ...)`. Esa comprobación
 * está dentro de `Informe::publicar()`, un único sitio. Si la duplicaras aquí,
 * tendrías dos verdades que algún día se van a contradecir.
 */
final readonly class PublicarInforme
{
    public function __construct(
        private InformeRepositoryInterface $informes,
    ) {}

    public function execute(int $id): Informe
    {
        $informe = $this->informes->porId($id) ?? throw InformeNoEncontrado::conId($id);

        return $this->informes->guardar($informe->publicar(new DateTimeImmutable()));
    }
}
