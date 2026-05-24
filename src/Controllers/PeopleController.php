<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\PeopleService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class PeopleController
{
    private const PER_PAGE = 60;

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $db,
        private readonly PeopleService $people,
        private readonly string $libraryPath
    ) {}

    public function browse(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $role   = in_array($params['role'] ?? '', ['author', 'artist', 'cast'], true)
            ? $params['role']
            : null;
        $page   = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        [$sql, $binds] = $role
            ? ['SELECT * FROM people WHERE role = ? ORDER BY name ASC LIMIT ? OFFSET ?', [$role, self::PER_PAGE, $offset]]
            : ['SELECT * FROM people ORDER BY name ASC LIMIT ? OFFSET ?', [self::PER_PAGE, $offset]];

        $people = $this->db->query($sql, $binds);

        $countSql    = $role ? 'SELECT COUNT(*) as n FROM people WHERE role = ?' : 'SELECT COUNT(*) as n FROM people';
        $countBinds  = $role ? [$role] : [];
        $total       = (int) ($this->db->first($countSql, $countBinds)['n'] ?? 0);
        $totalPages  = (int) ceil($total / self::PER_PAGE);

        $html = $this->twig->render('people/browse.html.twig', [
            'people'      => $people,
            'role'        => $role,
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $slug   = $args['slug'];
        $person = $this->db->first('SELECT * FROM people WHERE slug = ?', [$slug]);
        if (!$person) {
            $response->getBody()->write('Not found');
            return $response->withStatus(404);
        }

        $sections = $this->getWorkSections($person);

        $html = $this->twig->render('people/detail.html.twig', compact('person', 'sections'));
        $response->getBody()->write($html);
        return $response;
    }

    public function refresh(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->people->refreshPerson($id);
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getWorkSections(array $person): array
    {
        $name    = $person['name'];
        $libPath = $this->libraryPath;

        $makeRelUrl = fn(string $type, string $path): string =>
            rawurlencode(ltrim(str_replace($libPath . '/' . $type, '', $path), '/'));

        $sections = [];

        if ($person['role'] === 'author') {
            // Series entries — one card per series name
            $seriesRows = $this->db->query(
                'SELECT series as name,
                        MAX(poster) as poster,
                        MAX(year) as year,
                        MAX(author) as author,
                        \'series\' as kind
                 FROM media
                 WHERE type IN (\'books\', \'audiobooks\') AND author = ? AND series IS NOT NULL
                 GROUP BY series
                 ORDER BY MAX(year) DESC NULLS LAST, series',
                [$name]
            );
            // Standalone books (no series) — one card per book_name
            $standaloneRows = $this->db->query(
                'SELECT book_name as name,
                        MAX(poster) as poster,
                        MAX(year) as year,
                        MAX(author) as author,
                        MAX(CASE WHEN type = \'audiobooks\' THEN 1 ELSE 0 END) as has_audio,
                        MAX(CASE WHEN type = \'books\'      THEN 1 ELSE 0 END) as has_ebook,
                        \'book\' as kind
                 FROM media
                 WHERE type IN (\'books\', \'audiobooks\') AND author = ? AND series IS NULL
                   AND book_name IS NOT NULL
                 GROUP BY book_name
                 ORDER BY MAX(year) DESC NULLS LAST, book_name',
                [$name]
            );

            // Index by name for deduplication
            $seriesNames    = array_column($seriesRows, null, 'name');
            $standaloneNames = array_column($standaloneRows, null, 'name');

            $merged = [];
            // Add all series entries
            foreach ($seriesRows as $w) {
                $w['url']  = '/library/books/' . rawurlencode($w['author'] ?? $name) . '/' . rawurlencode($w['name']);
                $w['type'] = 'books';
                $merged[$w['name']] = $w;
            }
            // Add standalone books that don't share a name with a series
            foreach ($standaloneRows as $w) {
                if (isset($seriesNames[$w['name']])) {
                    continue; // series with same name already covers this
                }
                $w['url']  = '/library/books/' . rawurlencode($w['author'] ?? $name) . '/' . rawurlencode($w['name']);
                $w['type'] = ($w['has_audio'] ?? 0) ? 'audiobooks' : 'books';
                $merged[$w['name']] = $w;
            }

            // Sort by year DESC, then name
            usort($merged, fn($a, $b) =>
                ($b['year'] ?? 0) <=> ($a['year'] ?? 0) ?: strnatcasecmp($a['name'], $b['name'])
            );

            if ($merged) $sections['Books'] = ['items' => array_values($merged), 'square' => false];
        }

        if ($person['role'] === 'artist') {
            $music = $this->db->query(
                'SELECT COALESCE(series, title) as name, MAX(poster) as poster,
                        MAX(year) as year, MAX(author) as author, \'music\' as type
                 FROM v_media WHERE type = \'music\' AND author = ?
                 GROUP BY COALESCE(series, title)
                 ORDER BY MAX(year) DESC NULLS LAST',
                [$name]
            );
            foreach ($music as &$w) {
                $w['url'] = '/library/music/' . rawurlencode($w['author'] ?? $name)
                          . ($w['name'] ? '/' . rawurlencode($w['name']) : '');
            }
            unset($w);

            if ($music) $sections['Music'] = ['items' => $music, 'square' => true];
        }

        if ($person['role'] === 'cast') {
            $pattern = '%"' . str_replace(['"', '%', '_'], ['', '\%', '\_'], $name) . '"%';

            $movies = $this->db->query(
                'SELECT title as name, poster, year, \'movies\' as type, MIN(path) as path
                 FROM v_media WHERE type = \'movies\' AND metadata LIKE ?
                 GROUP BY title ORDER BY year DESC NULLS LAST',
                [$pattern]
            );
            foreach ($movies as &$w) {
                $w['url'] = '/library/movies/' . $makeRelUrl('movies', $w['path']);
            }
            unset($w);

            $shows = $this->db->query(
                'SELECT show_name as name, MAX(poster) as poster, MAX(year) as year, \'shows\' as type
                 FROM v_media WHERE type = \'shows\' AND metadata LIKE ?
                 GROUP BY show_name HAVING show_name IS NOT NULL
                 ORDER BY MAX(year) DESC NULLS LAST',
                [$pattern]
            );
            foreach ($shows as &$w) {
                $w['url'] = '/library/shows/' . rawurlencode($w['name']);
            }
            unset($w);

            if ($movies) $sections['Movies'] = ['items' => $movies, 'square' => false];
            if ($shows)  $sections['Shows']  = ['items' => $shows,  'square' => false];
        }

        return $sections;
    }
}
