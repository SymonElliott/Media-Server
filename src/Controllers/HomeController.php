<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HomeController
{
    private string $libraryPath;

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection  $db,
        Settings $settings,
    ) {
        $this->libraryPath = rtrim($settings->getEnv('LIBRARY_PATH', '/library'), '/');
    }

    public function index(Request $request, Response $response): Response
    {
        $rows   = $this->db->query('SELECT type, COUNT(*) as count, SUM(size) as total_size FROM media GROUP BY type');
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] = $row;
        }
        if (isset($counts['audiobooks'])) {
            $counts['books']['count']      = ($counts['books']['count']      ?? 0) + $counts['audiobooks']['count'];
            $counts['books']['total_size'] = ($counts['books']['total_size'] ?? 0) + $counts['audiobooks']['total_size'];
            unset($counts['audiobooks']);
        }

        $all = $this->inProgress();

        $watching  = array_values(array_filter($all, fn($r) => in_array($r['type'], ['movies', 'shows'], true)));
        $listening = array_values(array_filter($all, fn($r) => in_array($r['type'], ['music', 'audiobooks'], true)));
        $reading   = array_values(array_filter($all, fn($r) => in_array($r['type'], ['books'], true)));

        $html = $this->twig->render('home.html.twig', [
            'counts'    => $counts,
            'watching'  => $watching,
            'listening' => $listening,
            'reading'   => $reading,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function inProgress(): array
    {
        $userId = $_SESSION['user']['id'] ?? null;
        if (!$userId) return [];

        $rows = $this->db->query(
            'SELECT p.position, p.duration, p.completed, p.updated_at,
                    m.id, m.type, m.title, m.filename, m.poster, m.path,
                    m.show_name, m.season, m.episode, m.author, m.series
             FROM progress p
             JOIN media m ON p.media_id = m.id
             WHERE p.user_id = ? AND p.completed = 0 AND (p.position > 0 OR p.position_cfi IS NOT NULL)
             ORDER BY p.updated_at DESC
             LIMIT 24',
            [$userId]
        );

        foreach ($rows as &$row) {
            $row['url']     = $this->itemUrl($row['path'], $row['type']);
            $row['percent'] = $row['duration'] > 0
                ? min(99, (int) round($row['position'] / $row['duration'] * 100))
                : 0;
        }

        return $rows;
    }

    private function itemUrl(string $path, string $type): string
    {
        $rel   = substr($path, strlen($this->libraryPath) + 1); // e.g. "movies/Title/Title.mp4"
        $parts = explode('/', $rel);
        $encoded = array_map('rawurlencode', $parts);
        return '/library/' . implode('/', $encoded);
    }
}
