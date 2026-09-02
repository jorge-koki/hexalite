<?php

declare(strict_types=1);

namespace HexaLite\Examples\Modules\Informes\Infrastructure\Http\Controllers;

use HexaLite\Attributes\Controller;
use HexaLite\Attributes\Route;
use HexaLite\Examples\Modules\Informes\Application\Dtos\CrearInformeDto;
use HexaLite\Examples\Modules\Informes\Application\UseCases\CrearInforme;
use HexaLite\Examples\Modules\Informes\Application\UseCases\ListarInformes;
use HexaLite\Examples\Modules\Informes\Application\UseCases\PublicarInforme;
use HexaLite\Examples\Modules\Informes\Domain\Informe;
use HexaLite\Http\Request;
use HexaLite\Http\Response;

/**
 * ADAPTADOR DE ENTRADA. Traduce HTTP → caso de uso → HTTP. Y nada más.
 *
 * La prueba del algodón: si te encuentras escribiendo un `if` de negocio aquí
 * dentro, pregúntate si esa regla seguiría valiendo llamando al caso de uso desde
 * un cron o desde una cola de mensajes. Si la respuesta es sí, no pertenece al
 * controlador — bájala al dominio.
 *
 * Tampoco captura las excepciones del dominio. El front controller las mapea a
 * códigos HTTP con `registerExceptionHandler()`, así el dominio nunca necesita
 * enterarse de que existe un 404.
 */
#[Controller('/informes')]
final class InformeController
{
    /**
     * Autowiring: el contenedor construye los tres casos de uso y, al resolverlos,
     * les inyecta el adaptador que el provider enlazó al puerto. El controlador no
     * sabe —ni le importa— si detrás hay PostgreSQL o un array en memoria.
     */
    public function __construct(
        private readonly ListarInformes $listar,
        private readonly CrearInforme $crear,
        private readonly PublicarInforme $publicar,
    ) {}

    #[Route('', method: 'GET')]
    public function index(Request $request): Response
    {
        $idProyecto = (int) $request->input('id_proyecto', 1);

        return response([
            'data' => array_map($this->serializar(...), $this->listar->execute($idProyecto)),
        ]);
    }

    #[Route('', method: 'POST')]
    public function store(CrearInformeDto $dto): Response
    {
        // Si el body no cumple las reglas del DTO, el Router responde 422 y este
        // método ni siquiera llega a ejecutarse.
        $informe = $this->crear->execute($dto->titulo, $dto->id_proyecto);

        return response(['data' => $this->serializar($informe)], 201);
    }

    #[Route('/{id}/publicar', method: 'POST')]
    public function publish(int $id): Response
    {
        return response(['data' => $this->serializar($this->publicar->execute($id))]);
    }

    /**
     * La entidad NO se serializa sola con `json_encode`. La forma del JSON es una
     * decisión de la capa HTTP, no del dominio: si `Informe` expusiera un
     * `toArray()`, cada cambio en el contrato de la API te obligaría a tocar el
     * dominio. Exactamente el acoplamiento que vinimos a evitar.
     *
     * @return array<string, mixed>
     */
    private function serializar(Informe $informe): array
    {
        return [
            'id'           => $informe->id,
            'titulo'       => $informe->titulo,
            'id_proyecto'  => $informe->idProyecto,
            'estado'       => $informe->estado->value,
            'publicado_en' => $informe->publicadoEn?->format(DATE_ATOM),
        ];
    }
}
