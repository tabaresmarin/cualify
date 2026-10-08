<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Lead;

final class ConversationsController extends Controller
{
    /**
     * Cuantos hilos se pintan de una vez. Todas las conversaciones se muestran
     * en la misma pagina, asi que este tope protege al hosting de una base con
     * mucha actividad: no es un "solo la ultima", es un limite por pagina con
     * paginacion visible.
     */
    private const HILOS_POR_PAGINA = 20;

    /** Mensajes por hilo antes de recortar. */
    private const MENSAJES_POR_HILO = 200;

    public function index(Request $request, Response $response, array $params): never
    {
        /** @var Lead $leads */
        $leads = $this->app->make(Lead::class);

        $busqueda = trim((string) $request->query('q', ''));
        $pagina   = max(1, (int) $request->query('pagina', '1'));

        $totalHilos = $leads->conteoTelefonosConversando($busqueda);
        $totalPaginas = max(1, (int) ceil($totalHilos / self::HILOS_POR_PAGINA));

        // Una pagina vacia (por ejemplo al borrar el filtro) cae en la ultima.
        if ($pagina > $totalPaginas) {
            $pagina = $totalPaginas;
        }

        $telefonos = $leads->telefonosConversando(
            self::HILOS_POR_PAGINA,
            $busqueda,
            ($pagina - 1) * self::HILOS_POR_PAGINA
        );

        $hilos = $leads->conversacionesDe(
            array_column($telefonos, 'phone'),
            self::MENSAJES_POR_HILO
        );

        $this->render('conversations.index', [
            'titulo'          => 'Conversaciones',
            'telefonos'       => $telefonos,
            'hilos'           => $hilos,
            'busqueda'        => $busqueda,
            'pagina'          => $pagina,
            'totalPaginas'    => $totalPaginas,
            'totalHilos'      => $totalHilos,
            // Url antigua /conversations?tel=... : ahora solo sirve para
            // traer el hilo a la vista, ya no para elegir cual mostrar.
            'foco'            => trim((string) $request->query('tel', '')),
        ]);
    }
}