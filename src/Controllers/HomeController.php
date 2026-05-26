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
        private readonly Settings    $settings,
        string $libraryPath,
    ) {
        $this->libraryPath = rtrim($libraryPath, '/');
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

        $notices = $this->buildNotices($counts);

        $html = $this->twig->render('home.html.twig', [
            'counts'    => $counts,
            'watching'  => $watching,
            'listening' => $listening,
            'reading'   => $reading,
            'notices'   => $notices,
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
             JOIN v_media m ON p.media_id = m.id
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

    /**
     * Build a list of setup notices shown on the home page.
     * Each notice: ['id', 'icon', 'title', 'detail', 'action_label', 'action_url']
     */
    private function buildNotices(array $counts): array
    {
        $notices = [];

        // TMDB API key — required for movies & shows metadata
        if (!$this->settings->getEnv('TMDB_API_KEY')) {
            $notices[] = [
                'id'           => 'tmdb_key',
                'icon'         => '🔑',
                'title'        => 'TMDB API key not set',
                'detail'       => 'Movies and TV shows won\'t get titles, posters, or descriptions without it.',
                'action_label' => 'Open Settings',
                'action_url'   => '/settings#api-keys',
            ];
        }

        // Library not yet scanned
        $totalItems = array_sum(array_column($counts, 'count'));
        if ($totalItems === 0) {
            $notices[] = [
                'id'           => 'no_scan',
                'icon'         => '📂',
                'title'        => 'Library hasn\'t been scanned yet',
                'detail'       => 'Run a scan so your media files appear in the library.',
                'action_label' => 'Scan Now',
                'action_url'   => null, // handled by JS scan button
                'action_js'    => "document.querySelector('.btn-scan')?.click()",
            ];
        }

        // Media exists but none has been enriched (token was missing during scan)
        if ($totalItems > 0 && $this->settings->getEnv('TMDB_API_KEY')) {
            $unenriched = (int) ($this->db->first(
                'SELECT COUNT(*) as n FROM media
                 WHERE type IN ("movies","shows") AND metadata_fetched_at IS NULL'
            )['n'] ?? 0);
            $total_av = ($counts['movies']['count'] ?? 0) + ($counts['shows']['count'] ?? 0);
            if ($total_av > 0 && $unenriched === $total_av) {
                $notices[] = [
                    'id'           => 'meta_pending',
                    'icon'         => '🔄',
                    'title'        => 'Metadata not yet fetched',
                    'detail'       => 'Your library is indexed but metadata hasn\'t been pulled. Run a scan or use Refresh Metadata.',
                    'action_label' => 'Scan Now',
                    'action_url'   => null,
                    'action_js'    => "document.querySelector('.btn-scan')?.click()",
                ];
            }
        }

        return $notices;
    }
}
