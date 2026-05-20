<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AuthController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Connection  $db,
    ) {}

    public function loginPage(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user'])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if ($this->db->first('SELECT id FROM users LIMIT 1') === null) {
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }
        $html = $this->twig->render('login.html.twig', [
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = $request->getParsedBody() ?? [];
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $user = $this->db->first('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'],
            ];
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $response->withHeader('Location', '/login?error=1')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function setupPage(Request $request, Response $response): Response
    {
        if ($this->db->first('SELECT id FROM users LIMIT 1') !== null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        $html = $this->twig->render('setup.html.twig', [
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function setup(Request $request, Response $response): Response
    {
        if ($this->db->first('SELECT id FROM users LIMIT 1') !== null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $body     = $request->getParsedBody() ?? [];
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $confirm  = $body['confirm'] ?? '';

        if ($username === '' || strlen($password) < 8 || $password !== $confirm) {
            return $response->withHeader('Location', '/setup?error=1')->withStatus(302);
        }

        $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, password_hash($password, PASSWORD_BCRYPT), 'admin']
        );
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
