<?php

declare(strict_types=1);

namespace HexaLite\Tests\Examples;

use HexaLite\Examples\Modules\Informes\Application\UseCases\CrearInforme;
use HexaLite\Examples\Modules\Informes\Application\UseCases\ListarInformes;
use HexaLite\Examples\Modules\Informes\Application\UseCases\PublicarInforme;
use HexaLite\Examples\Modules\Informes\Domain\EstadoInforme;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeNoEncontrado;
use HexaLite\Examples\Modules\Informes\Domain\Exceptions\InformeYaPublicado;
use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Examples\Modules\Informes\Domain\Interfaces\InformeRepositoryInterface;
use HexaLite\Examples\Modules\Informes\Infrastructure\Persistence\InMemoryInformeRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ESTE fichero es el argumento entero a favor de la arquitectura hexagonal.
 *
 * Mira lo que NO hace falta para ejecutarlo: ni base de datos, ni servidor web,
 * ni petición HTTP, ni contenedor de dependencias, ni un solo mock. Se sustituye
 * el adaptador de persistencia por el de memoria —los dos cumplen el mismo
 * puerto— y las reglas de negocio se prueban en milisegundos.
 *
 * Cuando alguien te diga que la arquitectura hexagonal es "sobreingeniería",
 * enséñale este fichero y pregúntale cuánto tardaría en probar lo mismo con la
 * lógica metida dentro del controlador.
 */
final class InformesHexagonalTest extends TestCase
{
    private InformeRepositoryInterface $informes;

    protected function setUp(): void
    {
        // Cambiar de PostgreSQL a memoria es cambiar ESTA línea. Nada más.
        $this->informes = new InMemoryInformeRepository();
    }

    #[Test]
    public function crear_un_informe_le_asigna_id_y_lo_deja_en_borrador(): void
    {
        $informe = (new CrearInforme($this->informes))->execute('Informe anual', 7);

        $this->assertSame(1, $informe->id);
        $this->assertSame('Informe anual', $informe->titulo);
        $this->assertSame(EstadoInforme::Borrador, $informe->estado);
        $this->assertFalse($informe->estaPublicado());
        $this->assertNull($informe->publicadoEn);
    }

    #[Test]
    public function listar_solo_devuelve_los_informes_del_proyecto_pedido(): void
    {
        $crear = new CrearInforme($this->informes);
        $crear->execute('Del proyecto 1', 1);
        $crear->execute('Del proyecto 2', 2);
        $crear->execute('Otro del 1', 1);

        $delUno = (new ListarInformes($this->informes))->execute(1);

        $this->assertCount(2, $delUno);
        $this->assertSame(['Del proyecto 1', 'Otro del 1'], array_map(
            static fn (Informe $i): string => $i->titulo,
            $delUno,
        ));
    }

    #[Test]
    public function publicar_marca_el_informe_y_registra_la_fecha(): void
    {
        $creado = (new CrearInforme($this->informes))->execute('Informe anual', 1);

        $publicado = (new PublicarInforme($this->informes))->execute((int) $creado->id);

        $this->assertTrue($publicado->estaPublicado());
        $this->assertNotNull($publicado->publicadoEn);
    }

    #[Test]
    public function un_informe_no_se_puede_publicar_dos_veces(): void
    {
        $creado   = (new CrearInforme($this->informes))->execute('Informe anual', 1);
        $publicar = new PublicarInforme($this->informes);
        $publicar->execute((int) $creado->id);

        // La regla es del DOMINIO: se cumple igual llamando desde un test, desde
        // un cron o desde HTTP. Eso es justo lo que se pierde al meterla en el
        // controlador.
        $this->expectException(InformeYaPublicado::class);
        $publicar->execute((int) $creado->id);
    }

    #[Test]
    public function publicar_un_informe_inexistente_falla_con_una_excepcion_del_dominio(): void
    {
        $this->expectException(InformeNoEncontrado::class);

        (new PublicarInforme($this->informes))->execute(404);
    }

    #[Test]
    public function la_entidad_es_inmutable_publicar_devuelve_una_copia_nueva(): void
    {
        $borrador = Informe::nuevo('Informe anual', 1);

        $publicado = $borrador->publicar(new \DateTimeImmutable('2026-01-15 10:00:00'));

        // El original NO se muta: no existe forma de dejar la entidad a medias.
        $this->assertSame(EstadoInforme::Borrador, $borrador->estado);
        $this->assertSame(EstadoInforme::Publicado, $publicado->estado);
        $this->assertNotSame($borrador, $publicado);
    }
}
