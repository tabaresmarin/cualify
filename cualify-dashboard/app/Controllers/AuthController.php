<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function mostrarLogin(Request $request, Response $response, array $params): never
    {
        /** @var Auth $auth */
        $auth = $this->app->make(Auth::class);

        if ($auth->check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth.login', [
            'next'    => $this->destinoSeguro((string) $request->query('next', '')),
            'titulo'  => 'Iniciar sesion',
        ], 'auth');
    }

    public function login(Request $request, Response $response, array $params): never
    {
        $session = $this->app->make(Session::class);

        if (!$session->verifyToken((string) $request->input('_token'))) {
            $this->render('auth.login', [
                'error'   => 'La sesion expiro. Vuelve a intentarlo.',
                'email'   => (string) $request->input('email', ''),
                'next'    => '',
                'titulo'  => 'Iniciar sesion',
            ], 'auth');
        }

        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $next = $this->destinoSeguro((string) $request->input('next', ''));

        if ($email === '' || $password === '') {
            $session->flash('error', 'Ingresa tu correo y tu contrasena.');
            $this->redirect('/login');
        }

        $usuarios = $this->usuarios();
        $maxIntentos = (int) $this->app->config('auth.max_attempts', 5);
        $bloqueo = (int) $this->app->config('auth.lockout_seconds', 900);

        $intentos = $usuarios->intentosFallidos($email, $maxIntentos, $bloqueo);

        if ($intentos >= $maxIntentos) {
            $session->flash(
                'error',
                "Demasiados intentos fallidos. Espera unos minutos antes de reintentar."
            );
            $this->redirect('/login');
        }

        /** @var Auth $auth */
        $auth = $this->app->make(Auth::class);

        if (!$auth->attempt($email, $password)) {
            $usuarios->registrarIntento($email, $request->ip(), false);
            $session->flash('error', 'Credenciales incorrectas.');

            $restantes = max(0, $maxIntentos - $intentos - 1);
            if ($restantes > 0 && $restantes <= 3) {
                $session->flash('aviso', "Te quedan {$restantes} intentos antes del bloqueo temporal.");
            }

            $this->redirect('/login');
        }

        $usuarios->registrarIntento($email, $request->ip(), true);
        $session->flash('exito', 'Sesion iniciada.');
        $this->redirect($next);
    }

    public function logout(Request $request, Response $response, array $params): never
    {
        $session = $this->app->make(Session::class);

        if (!$session->verifyToken((string) $request->input('_token'))) {
            $this->redirect('/dashboard');
        }

        /** @var Auth $auth */
        $auth = $this->app->make(Auth::class);
        $auth->logout();

        $this->redirect('/login');
    }
}
