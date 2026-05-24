<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\AppLogger;
use App\Services\Metadata\MetadataService;
use App\Services\RenameService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MediaController
{
    public function __construct(
        private readonly Connection      $db,
        private readonly MetadataService $metadata,
        private readonly ?RenameService  $renamer = null,
        private readonly ?AppLogger      $log = null
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT * FROM v_media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        $response->getBody()->write(json_encode($item));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT id, title, type FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $type = $item['type'];

        // ── Base-table fields (common to all types) ─────────────────────────
        $baseFields = ['title', 'year', 'description', 'poster'];
        $baseSets = $baseParams = [];
        foreach ($baseFields as $field) {
            if (array_key_exists($field, $body)) {
                $baseSets[]   = "$field = ?";
                $baseParams[] = ($body[$field] !== '' && $body[$field] !== null) ? $body[$field] : null;
            }
        }
        if ($baseSets) {
            $baseParams[] = $id;
            $this->db->execute('UPDATE media SET ' . implode(', ', $baseSets) . ' WHERE id = ?', $baseParams);
        }

        // ── Extension-table fields routed by type ────────────────────────────
        match ($type) {
            'shows'                   => $this->updateShowsExt($id, $body),
            'music'                   => $this->updateMusicExt($id, $body),
            'books', 'audiobooks'     => $this->updateBooksExt($id, $body),
            default                   => null,
        };

        $allAllowed = ['title', 'year', 'description', 'poster', 'author', 'series',
                       'season', 'episode', 'series_order', 'book_name', 'book_version'];
        if ($baseSets || array_intersect_key($body, array_flip($allAllowed))) {
            $this->renamer?->renameItem($id);
            $this->log?->info('media', sprintf(
                'Updated "%s" (id=%d, type=%s): %s',
                $item['title'] ?? 'unknown',
                $id,
                $type,
                implode(', ', array_keys(array_intersect_key($body, array_flip($allAllowed))))
            ));
        }

        $response->getBody()->write(json_encode(['updated' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function updateShowsExt(int $id, array $body): void
    {
        $sets = $params = [];
        foreach (['season', 'episode'] as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]   = "$field = ?";
                $params[] = ($body[$field] !== '' && $body[$field] !== null) ? $body[$field] : null;
            }
        }
        if ($sets) {
            $params[] = $id;
            $this->db->execute('UPDATE media_shows SET ' . implode(', ', $sets) . ' WHERE media_id = ?', $params);
        }
    }

    private function updateMusicExt(int $id, array $body): void
    {
        // Map the generic body keys to the music extension table's column names
        $map = ['author' => 'artist', 'series' => 'album', 'series_order' => 'track_order'];
        $sets = $params = [];
        foreach ($map as $bodyKey => $col) {
            if (array_key_exists($bodyKey, $body)) {
                $sets[]   = "$col = ?";
                $params[] = ($body[$bodyKey] !== '' && $body[$bodyKey] !== null) ? $body[$bodyKey] : null;
            }
        }
        if ($sets) {
            $params[] = $id;
            $this->db->execute('UPDATE media_music SET ' . implode(', ', $sets) . ' WHERE media_id = ?', $params);
        }
    }

    private function updateBooksExt(int $id, array $body): void
    {
        $sets = $params = [];
        foreach (['author', 'series', 'series_order', 'book_name', 'book_version'] as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]   = "$field = ?";
                $params[] = ($body[$field] !== '' && $body[$field] !== null) ? $body[$field] : null;
            }
        }
        if ($sets) {
            $params[] = $id;
            $this->db->execute('UPDATE media_books SET ' . implode(', ', $sets) . ' WHERE media_id = ?', $params);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT path, title, type FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        $filePath = $item['path'];
        if (file_exists($filePath)) {
            unlink($filePath);
            // Remove empty parent directory (e.g. a movie's own folder)
            $parentDir = dirname($filePath);
            if (is_dir($parentDir)) {
                $remaining = array_diff((array) scandir($parentDir), ['.', '..', '.DS_Store']);
                if (empty($remaining)) {
                    @rmdir($parentDir);
                }
            }
        }
        $this->db->execute('DELETE FROM media WHERE id = ?', [$id]);
        $this->log?->info('media', sprintf(
            'Deleted "%s" (id=%d, type=%s)',
            $item['title'] ?? basename($filePath),
            $id,
            $item['type'] ?? '?'
        ));

        $response->getBody()->write(json_encode(['deleted' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteGroup(Request $request, Response $response): Response
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $type = $body['type'] ?? '';
        $name = $body['name'] ?? '';

        if (!$type || !$name) {
            $response->getBody()->write(json_encode(['error' => 'type and name required']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $colHint = $body['col'] ?? '';
        $col = match (true) {
            $type === 'shows'                               => 'show_name',
            $type === 'music'  && $colHint === 'series'     => 'series',
            $type === 'music'                               => 'author',
            $type === 'audiobooks' && $colHint === 'series' => 'series',
            $type === 'audiobooks'                          => 'book_name',
            $type === 'books'  && $colHint === 'series'     => 'series',
            default                                         => null,
        };
        if (!$col) {
            $response->getBody()->write(json_encode(['error' => 'not a group type']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Series deletes are scoped to the specific type(s) to avoid cross-type collisions.
        // All WHERE clauses use extension-table subqueries because type-specific columns
        // are no longer on the base media table.
        if ($col === 'series' && $type === 'music') {
            $items = $this->db->query("SELECT path FROM v_media WHERE series = ? AND type = 'music'", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_music WHERE album = ?)",
                [$name]
            );
        } elseif ($col === 'series' && in_array($type, ['books', 'audiobooks'], true)) {
            $items = $this->db->query("SELECT path FROM v_media WHERE series = ? AND type IN ('books','audiobooks')", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_books WHERE series = ?)",
                [$name]
            );
        } elseif ($col === 'show_name') {
            $items = $this->db->query("SELECT path FROM v_media WHERE show_name = ?", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_shows WHERE show_name = ?)",
                [$name]
            );
        } elseif ($col === 'author' && $type === 'music') {
            $items = $this->db->query("SELECT path FROM v_media WHERE author = ? AND type = 'music'", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_music WHERE artist = ?)",
                [$name]
            );
        } elseif ($col === 'book_name') {
            $items = $this->db->query("SELECT path FROM v_media WHERE book_name = ?", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_books WHERE book_name = ?)",
                [$name]
            );
        } else {
            // Fallback: author for books/audiobooks
            $items = $this->db->query("SELECT path FROM v_media WHERE author = ? AND type IN ('books','audiobooks')", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute(
                "DELETE FROM media WHERE id IN (SELECT media_id FROM media_books WHERE author = ?)",
                [$name]
            );
        }

        $count = count($items);
        $this->log?->info('media', sprintf('Deleted group "%s" (%s, %d items)', $name, $type, $count));

        $response->getBody()->write(json_encode(['deleted' => $count]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchMetadata(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $query     = trim($params['query'] ?? '');
        $mediaType = $params['media_type'] ?? 'movies';
        $author    = trim($params['author'] ?? '') ?: null;

        if (!$query) {
            $response->getBody()->write(json_encode([]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $provider = $params['provider'] ?? null;
        $results  = $provider
            ? $this->metadata->searchByProvider($provider, $query, $author)
            : $this->metadata->searchExternal($mediaType, $query, $author);

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function applyMatch(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = json_decode((string) $request->getBody(), true) ?? [];

        $source     = $body['source']      ?? '';
        $externalId = $body['external_id'] ?? '';

        if (!$source || !$externalId) {
            $response->getBody()->write(json_encode(['error' => 'source and external_id required']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $this->metadata->applyExternalMatch($id, $source, $externalId);
        $this->log?->info('metadata', "Applied match for id={$id}: {$source}/{$externalId}");

        $response->getBody()->write(json_encode(['applied' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function search(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query  = trim($params['q'] ?? '');
        $type   = $params['type'] ?? null;
        $page   = max(1, (int) ($params['page'] ?? 1));
        $limit  = min(100, (int) ($params['limit'] ?? 20)); // Max 100 items per page
        $offset = ($page - 1) * $limit;

        if (!$query) {
            $response->getBody()->write(json_encode([
                'items' => [],
                'total' => 0,
                'page'  => $page,
                'pages' => 0,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Build search query with wildcards
        $searchTerm = "%{$query}%";

        $whereClause  = '(title LIKE ? OR author LIKE ? OR show_name LIKE ? OR series LIKE ? OR book_name LIKE ?)';
        $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];

        // Build type filter separately so count and fetch queries share the same param set
        $typeFilter  = '';
        $typeParams  = [];
        if ($type && in_array($type, ['movies', 'shows', 'music', 'books', 'audiobooks'], true)) {
            $typeFilter = ' AND type = ?';
            $typeParams = [$type];
        }

        $countQuery = "SELECT COUNT(*) as total FROM v_media WHERE {$whereClause}{$typeFilter}";
        $total      = $this->db->first($countQuery, array_merge($searchParams, $typeParams))['total'] ?? 0;

        $baseQuery = "SELECT * FROM v_media WHERE {$whereClause}{$typeFilter} ORDER BY type, title LIMIT ? OFFSET ?";
        $items     = $this->db->query($baseQuery, array_merge($searchParams, $typeParams, [$limit, $offset]));

        $response->getBody()->write(json_encode([
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => ceil($total / $limit),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
