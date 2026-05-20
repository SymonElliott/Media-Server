<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\RealDebridService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RealDebridController
{
    private const VALID_CATEGORIES = ['movies', 'shows', 'music', 'books'];

    public function __construct(
        private readonly Connection        $db,
        private readonly RealDebridService $rd
    ) {}

    /**
     * POST /rd/add
     * Body: { magnet?: string, url?: string, category: string }
     */
    public function add(Request $request, Response $response): Response
    {
        if (!$this->rd->isConfigured()) {
            return $this->json($response, ['error' => 'Real-Debrid API key not configured'], 503);
        }

        $body     = json_decode((string) $request->getBody(), true) ?? [];
        $magnet   = trim($body['magnet'] ?? '');
        $url      = trim($body['url']    ?? '');
        $category = $body['category'] ?? '';

        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return $this->json($response, ['error' => 'Invalid category'], 400);
        }
        if (!$magnet && !$url) {
            return $this->json($response, ['error' => 'magnet or url required'], 400);
        }

        try {
            if ($magnet) {
                $torrentId = $this->rd->addMagnet($magnet);
                $this->rd->selectFiles($torrentId);
                $this->db->execute(
                    'INSERT OR REPLACE INTO rd_downloads (id, type, status, category, added_at, updated_at)
                     VALUES (?, "torrent", "queued", ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                    [$torrentId, $category]
                );
                $this->spawnFetch($torrentId);
                return $this->json($response, ['id' => $torrentId, 'type' => 'torrent']);
            }

            // Direct URL — unrestrict immediately
            $unrestricted = $this->rd->unrestrictLink($url);
            $dlId         = 'link_' . md5($url . time());
            $this->db->execute(
                'INSERT OR REPLACE INTO rd_downloads (id, type, status, category, filename, size, added_at, updated_at)
                 VALUES (?, "link", "fetching", ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$dlId, $category, $unrestricted['filename'] ?? null, $unrestricted['filesize'] ?? null]
            );
            $this->spawnFetch($dlId, $unrestricted['download'], $category);
            return $this->json($response, ['id' => $dlId, 'type' => 'link']);

        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 502);
        }
    }

    /**
     * GET /rd/status/{id}
     * Returns current status of a download from our DB, augmented with live RD data for torrents.
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        $id  = $args['id'];
        $row = $this->db->first('SELECT * FROM rd_downloads WHERE id = ?', [$id]);
        if (!$row) return $this->json($response, ['error' => 'Not found'], 404);

        // For active torrent downloads, pull fresh status from RD
        if ($row['type'] === 'torrent' && !in_array($row['status'], ['done', 'error'], true)) {
            try {
                $info            = $this->rd->getTorrentInfo($id);
                $row['rd_status'] = $info['status']   ?? null;
                $row['progress']  = $info['progress']  ?? 0;
                $row['filename']  = $info['filename']  ?? $row['filename'];
                $row['size']      = $info['bytes']     ?? $row['size'];
            } catch (\Throwable) {
                // Return whatever we have in DB
            }
        }

        return $this->json($response, $row);
    }

    /**
     * GET /rd/queue
     * Returns all downloads ordered by newest first.
     */
    public function queue(Request $request, Response $response): Response
    {
        $rows = $this->db->query(
            'SELECT * FROM rd_downloads ORDER BY added_at DESC LIMIT 50'
        );
        return $this->json($response, $rows);
    }

    /**
     * DELETE /rd/{id}
     * Remove a download record (and optionally cancel on RD).
     */
    public function remove(Request $request, Response $response, array $args): Response
    {
        $id  = $args['id'];
        $row = $this->db->first('SELECT * FROM rd_downloads WHERE id = ?', [$id]);
        if (!$row) return $this->json($response, ['error' => 'Not found'], 404);

        if ($row['type'] === 'torrent' && $row['status'] !== 'done') {
            try { $this->rd->deleteTorrent($id); } catch (\Throwable) {}
        }

        $this->db->execute('DELETE FROM rd_downloads WHERE id = ?', [$id]);
        return $this->json($response, ['deleted' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function spawnFetch(string $id, ?string $directUrl = null, ?string $category = null): void
    {
        $php    = PHP_BINARY;
        $script = realpath(dirname(__DIR__, 2) . '/bin/rd_fetch.php');
        if (!$script) return;

        $cmd = sprintf('%s %s %s', escapeshellarg($php), escapeshellarg($script), escapeshellarg($id));
        if ($directUrl) {
            $cmd .= ' ' . escapeshellarg($directUrl) . ' ' . escapeshellarg($category ?? '');
        }
        exec($cmd . ' > /dev/null 2>&1 &');
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
