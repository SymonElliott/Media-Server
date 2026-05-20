<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProgressController
{
    public function __construct(private readonly Connection $db) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $userId  = $_SESSION['user']['id'] ?? null;
        $mediaId = (int) $args['id'];

        if (!$userId) {
            return $this->json($response, null);
        }

        $row = $this->db->first(
            'SELECT position, duration, position_cfi, completed FROM progress WHERE user_id = ? AND media_id = ?',
            [$userId, $mediaId]
        );

        return $this->json($response, $row ?? ['position' => 0, 'duration' => 0, 'position_cfi' => null, 'completed' => 0]);
    }

    public function upsert(Request $request, Response $response, array $args): Response
    {
        $userId  = $_SESSION['user']['id'] ?? null;
        $mediaId = (int) $args['id'];

        if (!$userId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }

        $body      = json_decode((string) $request->getBody(), true) ?? [];
        $position  = (float) ($body['position']  ?? 0);
        $duration  = (float) ($body['duration']  ?? 0);
        $completed = (int)   ($body['completed'] ?? 0);
        $cfi       = isset($body['position_cfi']) ? (string) $body['position_cfi'] : null;

        // Auto-complete if within 95% of duration
        if ($duration > 0 && $position / $duration >= 0.95) {
            $completed = 1;
        }

        $this->db->execute(
            'INSERT INTO progress (user_id, media_id, position, duration, position_cfi, completed, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(user_id, media_id) DO UPDATE SET
               position     = excluded.position,
               duration     = excluded.duration,
               position_cfi = COALESCE(excluded.position_cfi, position_cfi),
               completed    = excluded.completed,
               updated_at   = CURRENT_TIMESTAMP',
            [$userId, $mediaId, $position, $duration, $cfi, $completed]
        );

        return $this->json($response, ['saved' => true, 'completed' => (bool) $completed]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId  = $_SESSION['user']['id'] ?? null;
        $mediaId = (int) $args['id'];

        if (!$userId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }

        $this->db->execute(
            'DELETE FROM progress WHERE user_id = ? AND media_id = ?',
            [$userId, $mediaId]
        );

        return $this->json($response, ['deleted' => true]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
