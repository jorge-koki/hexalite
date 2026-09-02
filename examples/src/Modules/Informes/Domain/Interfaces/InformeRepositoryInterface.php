<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Domain\Interfaces;

use HexaLite\Examples\Modules\Informes\Domain\Informe;

/**
 * PUERTO DE SALIDA — la pieza clave de la arquitectura hexagonal, y la que casi
 * todo el mundo se salta.
 *
 * El caso de uso depende de ESTA interfaz, nunca de `PdoInformeRepository`. Con
 * eso, la dependencia apunta hacia adentro: Infrastructure conoce a Domain, y
 * jamás al revés. Cambiar PostgreSQL por memoria, por un CSV o por una API ajena
 * es escribir otro adaptador y tocar UNA línea del provider.
 *
 * Mira también el vocabulario: `guardar`, `porId`, `delProyecto`. No hay
 * `select`, ni `insert`, ni `execute`. La interfaz habla el idioma del negocio
 * porque pertenece al negocio; el SQL es un detalle del adaptador.
 */
interface InformeRepositoryInterface
{
    /** @return list<Informe> */
    public function delProyecto(int $idProyecto): array;

    public function porId(int $id): ?Informe;

    /** Devuelve el informe ya persistido, con su id asignado. */
    public function guardar(Informe $informe): Informe;
}
