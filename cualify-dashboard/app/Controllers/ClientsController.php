<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Client;

final class ClientsController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        /** @var Client $clientModel */
        $clientModel = $this->app->make(Client::class);

        $this->render('clients.index', [
            'titulo'   => 'Clientes',
            'clientes' => $clientModel->listarClientesConEstadisticas(),
        ]);
    }

    public function crear(Request $request, Response $response, array $params): never
    {
        $this->render('clients.form', [
            'titulo'  => 'Nuevo Cliente',
            'cliente' => null,
        ]);
    }

    public function guardar(Request $request, Response $response, array $params): never
    {
        /** @var Client $clientModel */
        $clientModel = $this->app->make(Client::class);
        /** @var Session $session */
        $session = $this->app->make(Session::class);

        $name          = trim((string) $request->input('name', ''));
        $phoneNumberId = trim((string) $request->input('whatsapp_phone_number_id', ''));
        $accessToken   = trim((string) $request->input('access_token', ''));

        if ($name === '' || $phoneNumberId === '') {
            $session->flash('error', 'El nombre y el ID de WhatsApp Phone Number son obligatorios.');
            $this->redirect('/clients/crear');
        }

        try {
            $clientModel->crear($name, $phoneNumberId, $accessToken !== '' ? $accessToken : null);
            $session->flash('exito', 'Cliente registrado correctamente.');
        } catch (\Throwable $e) {
            $session->flash('error', 'Error al guardar cliente: ' . $e->getMessage());
            $this->redirect('/clients/crear');
        }

        $this->redirect('/clients');
    }

    public function editar(Request $request, Response $response, array $params): never
    {
        /** @var Client $clientModel */
        $clientModel = $this->app->make(Client::class);
        /** @var Session $session */
        $session = $this->app->make(Session::class);

        $id = (int) ($params['id'] ?? 0);
        $cliente = $clientModel->find($id);

        if ($cliente === null) {
            $session->flash('error', 'El cliente especificado no existe.');
            $this->redirect('/clients');
        }

        $this->render('clients.form', [
            'titulo'  => 'Editar Cliente',
            'cliente' => $cliente,
        ]);
    }

    public function actualizar(Request $request, Response $response, array $params): never
    {
        /** @var Client $clientModel */
        $clientModel = $this->app->make(Client::class);
        /** @var Session $session */
        $session = $this->app->make(Session::class);

        $id            = (int) ($params['id'] ?? 0);
        $name          = trim((string) $request->input('name', ''));
        $phoneNumberId = trim((string) $request->input('whatsapp_phone_number_id', ''));
        $accessToken   = trim((string) $request->input('access_token', ''));

        if ($id <= 0 || $name === '' || $phoneNumberId === '') {
            $session->flash('error', 'El nombre y el ID de WhatsApp Phone Number son obligatorios.');
            $this->redirect("/clients/{$id}/editar");
        }

        try {
            $clientModel->actualizar($id, $name, $phoneNumberId, $accessToken !== '' ? $accessToken : null);
            $session->flash('exito', 'Cliente actualizado correctamente.');
        } catch (\Throwable $e) {
            $session->flash('error', 'Error al actualizar cliente: ' . $e->getMessage());
            $this->redirect("/clients/{$id}/editar");
        }

        $this->redirect('/clients');
    }
}
