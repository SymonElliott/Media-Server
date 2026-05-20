<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class UsersController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Connection  $db,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        $users = $this->db->query(
            'SELECT id, username, role, created_at FROM users ORDER BY created_at ASC'
        );
        $html = $this->twig->render('users.html.twig', ['users' => $users]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['error' => 'Forbidden'], 403);
        }
        $body     = $request->getParsedBody() ?? [];
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $role     = in_array($body['role'] ?? '', ['admin', 'user'], true) ? $body['role'] : 'user';

        if ($username === '' || strlen($password) < 8) {
            return $this->json($response, ['error' => 'Username required and password must be at least 8 characters'], 422);
        }
        if ($this->db->first('SELECT id FROM users WHERE username = ?', [$username])) {
            return $this->json($response, ['error' => 'Username already taken'], 409);
        }

        $id = $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, password_hash($password, PASSWORD_BCRYPT), $role]
        );
        return $this->json($response, ['id' => $id, 'username' => $username, 'role' => $role]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['error' => 'Forbidden'], 403);
        }
        $id = (int) $args['id'];
        if ($id === ($_SESSION['user']['id'] ?? 0)) {
            return $this->json($response, ['error' => 'Cannot delete your own account'], 422);
        }

        $target = $this->db->first('SELECT role FROM users WHERE id = ?', [$id]);
        if (!$target) {
            return $this->json($response, ['error' => 'User not found'], 404);
        }
        if ($target['role'] === 'admin') {
            $adminCount = (int) ($this->db->first('SELECT COUNT(*) AS n FROM users WHERE role = ?', ['admin'])['n'] ?? 0);
            if ($adminCount <= 1) {
                return $this->json($response, ['error' => 'Cannot delete the last admin'], 422);
            }
        }

        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
        return $this->json($response, ['deleted' => true]);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $body    = $request->getParsedBody() ?? [];
        $current = $body['current_password'] ?? '';
        $new     = $body['new_password'] ?? '';
        $userId  = $_SESSION['user']['id'] ?? null;

        if (!$userId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }
        if (strlen($new) < 8) {
            return $this->json($response, ['error' => 'New password must be at least 8 characters'], 422);
        }
        $user = $this->db->first('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            return $this->json($response, ['error' => 'Current password is incorrect'], 422);
        }

        $this->db->execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), $userId]
        );
        return $this->json($response, ['saved' => true]);
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
