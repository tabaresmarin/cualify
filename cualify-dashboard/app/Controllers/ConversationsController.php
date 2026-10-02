<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Lead;

final class ConversationsController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        /** @var Lead $leads */
        $leads = $this->app->make(Lead::class);

        $telefono = trim((string) $request->query('tel', ''));

        // Sin telefono explicito, abrimos la conversacion mas reciente.
        if ($telefono === '') {
            $telefono = (string) ($leads->telefonosConversando(1)[0]['phone'] ?? '');
        }

        $this->render('conversations.index', [
            'titulo'       => 'Conversaciones',
            'telefonos'    => $leads->telefonosConversando(100),
            'telefono'     => $telefono,
            'conversacion' => $telefono !== '' ? $leads->conversacion($telefono, 200) : [],
            'lead'         => $telefono !== '' ? $leads->porTelefono($telefono) : null,
        ]);
    }
}
