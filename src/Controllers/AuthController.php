<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;

class AuthController
{
    public function handleLogin(): void
    {
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $csrf     = $_POST['_csrf']         ?? '';

        // CSRF check
        if (!Session::validateCsrf($csrf)) {
            Session::flash('login_error', 'Invalid request. Please try again.');
            header('Location: /login');
            exit;
        }

        // Rate limit check
        if (empty($email) || empty($password)) {
            Session::flash('login_error', 'Email and password are required.');
            header('Location: /login');
            exit;
        }

        $user = Auth::attempt($email, $password, $ip);

        if ($user === null) {
            Session::flash('login_error', 'Invalid email or password.');
            header('Location: /login');
            exit;
        }

        // Redirect to originally requested URL if stored, else dashboard
        $redirect = Session::get('intended_url', '/dashboard');
        Session::remove('intended_url');
        header('Location: ' . $redirect);
        exit;
    }
}
