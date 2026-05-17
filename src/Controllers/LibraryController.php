<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\LibraryScanner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class LibraryController
{
    private const VALID_TYPES = ['movies', 'shows', 'music', 'audiobooks', 'books'];

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $db,
        private readonly LibraryScanner $scanner,
        private readonly string $libraryPath
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('library/index.html.twig', [
            'types' => self::VALID_TYPES,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function browse(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        if (!in_array($type, self::VALID_TYPES, true)) {
            return $response->withStatus(404);
        }

        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $limit  = 60;
        $offset = ($page - 1) * $limit;

        // Group shows by show_name, music by author, everything else flat
        [$items, $total, $grouped] = match ($type) {
            'shows' => $this->browseShows($offset, $limit),
            'music' => $this->browseMusic($offset, $limit),
            default => $this->browseFlat($type, $offset, $limit),
        };

        $html = $this->twig->render('library/browse.html.twig', [
            'type'    => $type,
            'items'   => $items,
            'grouped' => $grouped,
            'page'    => $page,
            'pages'   => (int) ceil($total / $limit),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function item(Request $request, Response $response, array $args): Response
    {
        $type     = $args['type'];
        $urlPath  = $args['path'] ?? '';

        if (!in_array($type, self::VALID_TYPES, true)) {
            return $response->withStatus(404);
        }

        // Check if this is a directory-level browse (show/season/author drill-down)
        $dirPath  = $this->libraryPath . '/' . $type . '/' . $urlPath;
        if (is_dir($dirPath)) {
            return $this->browseDirectory($response, $type, $urlPath, $dirPath);
        }

        // Otherwise treat as a file item
        $fullPath = realpath($dirPath);
        $item = $this->db->first('SELECT * FROM media WHERE path = ?', [$fullPath]);

        if (!$item) {
            return $response->withStatus(404);
        }

        $item['stream_url'] = '/stream/' . $type . '/' . $urlPath;

        $html = $this->twig->render('library/item.html.twig', [
            'item' => $item,
            'type' => $type,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function scan(Request $request, Response $response): Response
    {
        $stats = $this->scanner->scan();
        $response->getBody()->write(json_encode($stats));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    private function browseFlat(string $type, int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT * FROM media WHERE type = ? ORDER BY title LIMIT ? OFFSET ?',
            [$type, $limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(*) as n FROM media WHERE type = ?', [$type]);
        return [$items, (int) ($total['n'] ?? 0), false];
    }

    private function browseShows(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT show_name, COUNT(*) as episode_count FROM media WHERE type = "shows" AND show_name IS NOT NULL
             GROUP BY show_name ORDER BY show_name LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT show_name) as n FROM media WHERE type = "shows"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseMusic(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT author, COUNT(*) as track_count FROM media WHERE type = "music" AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT author) as n FROM media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseDirectory(Response $response, string $type, string $urlPath, string $dirPath): Response
    {
        $entries = [];
        foreach (scandir($dirPath) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullEntry = $dirPath . '/' . $entry;
            $entries[] = [
                'name'     => $entry,
                'is_dir'   => is_dir($fullEntry),
                'url_path' => $urlPath . '/' . $entry,
                'size'     => is_file($fullEntry) ? filesize($fullEntry) : null,
            ];
        }

        usort($entries, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strnatcasecmp($a['name'], $b['name']));

        $html = $this->twig->render('library/directory.html.twig', [
            'type'     => $type,
            'path'     => $urlPath,
            'entries'  => $entries,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
