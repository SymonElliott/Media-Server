<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\Metadata\MetadataService;
use App\Services\RenameService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MediaController
{
    public function __construct(
        private readonly Connection      $db,
        private readonly MetadataService $metadata,
        private readonly ?RenameService  $renamer = null
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
        $item = $this->db->first('SELECT id FROM media WHERE id = ?', [$id]);
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
        }

        $response->getBody()->write(json_encode(['updated' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT path FROM media WHERE id = ?', [$id]);
        if (!$item) return $response->withStatus(404);

        if (file_exists($item['path'])) {
            unlink($item['path']);
        }
        $this->db->execute('DELETE FROM media WHERE id = ?', [$id]);

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

        $response->getBody()->write(json_encode(['deleted' => count($items)]));
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

        $response->getBody()->write(json_encode(['applied' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
