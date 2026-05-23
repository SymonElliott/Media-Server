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
        $item = $this->db->first('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        $response->getBody()->write(json_encode($item));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT id, title, type FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        $body    = json_decode((string) $request->getBody(), true) ?? [];
        $allowed = ['title', 'year', 'description', 'author', 'series', 'season', 'episode', 'poster', 'series_order'];

        $sets = $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]   = "$field = ?";
                $params[] = ($body[$field] !== '' && $body[$field] !== null) ? $body[$field] : null;
            }
        }

        if ($sets) {
            $params[] = $id;
            $this->db->execute('UPDATE media SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            $this->renamer?->renameItem($id);
            $this->log?->info('media', sprintf(
                'Updated "%s" (id=%d, type=%s): %s',
                $item['title'] ?? 'unknown',
                $id,
                $item['type'] ?? '?',
                implode(', ', array_keys(array_intersect_key($body, array_flip($allowed))))
            ));
        }

        $response->getBody()->write(json_encode(['updated' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT path, title, type FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        if (file_exists($item['path'])) {
            unlink($item['path']);
        }
        $this->db->execute('DELETE FROM media WHERE id = ?', [$id]);
        $this->log?->info('media', sprintf(
            'Deleted "%s" (id=%d, type=%s)',
            $item['title'] ?? basename($item['path']),
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

        // Series deletes are scoped to the specific type(s) to avoid cross-type collisions
        if ($col === 'series' && $type === 'music') {
            $items = $this->db->query("SELECT path FROM media WHERE series = ? AND type = 'music'", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute("DELETE FROM media WHERE series = ? AND type = 'music'", [$name]);
        } elseif ($col === 'series' && in_array($type, ['books', 'audiobooks'], true)) {
            $items = $this->db->query("SELECT path FROM media WHERE series = ? AND type IN ('books','audiobooks')", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute("DELETE FROM media WHERE series = ? AND type IN ('books','audiobooks')", [$name]);
        } else {
            $items = $this->db->query("SELECT path FROM media WHERE $col = ?", [$name]);
            foreach ($items as $row) { if (file_exists($row['path'])) unlink($row['path']); }
            $this->db->execute("DELETE FROM media WHERE $col = ?", [$name]);
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
                'page' => $page,
                'pages' => 0
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Build search query with wildcards
        $searchTerm = "%{$query}%";
        
        // Base query - search in title, author, show_name, series, book_name
        $baseQuery = "SELECT * FROM media WHERE 
            (title LIKE ? OR author LIKE ? OR show_name LIKE ? OR series LIKE ? OR book_name LIKE ?)";
        
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
        
        // Filter by type if specified
        if ($type && in_array($type, ['movies', 'shows', 'music', 'books', 'audiobooks'])) {
            $baseQuery .= " AND type = ?";
            $params[] = $type;
        }
        
        // Add ordering
        $baseQuery .= " ORDER BY type, title LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM media WHERE 
            (title LIKE ? OR author LIKE ? OR show_name LIKE ? OR series LIKE ? OR book_name LIKE ?)";
        
        if ($type && in_array($type, ['movies', 'shows', 'music', 'books', 'audiobooks'])) {
            $countQuery .= " AND type = ?";
            $params[] = $type;
        }
        
        $total = $this->db->first($countQuery, array_slice($params, 0, 5))['total'] ?? 0;
        
        // Get items
        $items = $this->db->query($baseQuery, $params);
        
        $response->getBody()->write(json_encode([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
}
