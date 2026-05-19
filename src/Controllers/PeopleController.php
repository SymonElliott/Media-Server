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

        $works = $this->getWorks($person);

        $html = $this->twig->render('people/detail.html.twig', compact('person', 'works'));
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

    private function getWorks(array $person): array
    {
        $name    = $person['name'];
        $libPath = $this->libraryPath;

        $makeRelUrl = fn(string $type, string $path): string =>
            rawurlencode(ltrim(str_replace($libPath . '/' . $type, '', $path), '/'));

        if ($person['role'] === 'author') {
            $audiobooks = $this->db->query(
                'SELECT book_name as name, MAX(poster) as poster, MAX(year) as year,
                        MAX(author) as author, "audiobooks" as type
                 FROM media WHERE type = "audiobooks" AND author = ?
                 GROUP BY book_name HAVING book_name IS NOT NULL
                 ORDER BY MAX(year) DESC NULLS LAST, book_name',
                [$name]
            );
            foreach ($audiobooks as &$w) {
                $w['url'] = '/library/audiobooks/' . rawurlencode($w['author'] ?? $name) . '/' . rawurlencode($w['name']);
            }
            unset($w);

            $books = $this->db->query(
                'SELECT title as name, poster, year, "books" as type, MIN(path) as path
                 FROM media WHERE type = "books" AND author = ?
                 GROUP BY title ORDER BY year DESC NULLS LAST, title',
                [$name]
            );
            foreach ($books as &$w) {
                $w['url'] = '/library/books/' . $makeRelUrl('books', $w['path']);
            }
            unset($w);

            return array_merge($audiobooks, $books);
        }

        if ($person['role'] === 'artist') {
            $works = $this->db->query(
                'SELECT COALESCE(series, title) as name, MAX(poster) as poster,
                        MAX(year) as year, MAX(author) as author, "music" as type
                 FROM media WHERE type = "music" AND author = ?
                 GROUP BY COALESCE(series, title)
                 ORDER BY MAX(year) DESC NULLS LAST',
                [$name]
            );
            foreach ($works as &$w) {
                $w['url'] = '/library/music/' . rawurlencode($w['author'] ?? $name)
                          . ($w['name'] ? '/' . rawurlencode($w['name']) : '');
            }
            unset($w);
            return $works;
        }

        if ($person['role'] === 'cast') {
            $pattern = '%"' . str_replace(['"', '%', '_'], ['', '\%', '\_'], $name) . '"%';

            $movies = $this->db->query(
                'SELECT title as name, poster, year, "movies" as type, MIN(path) as path
                 FROM media WHERE type = "movies" AND metadata LIKE ?
                 GROUP BY title ORDER BY year DESC NULLS LAST',
                [$pattern]
            );
            foreach ($movies as &$w) {
                $w['url'] = '/library/movies/' . $makeRelUrl('movies', $w['path']);
            }
            unset($w);

            $shows = $this->db->query(
                'SELECT show_name as name, MAX(poster) as poster, MAX(year) as year, "shows" as type
                 FROM media WHERE type = "shows" AND metadata LIKE ?
                 GROUP BY show_name HAVING show_name IS NOT NULL
                 ORDER BY MAX(year) DESC NULLS LAST',
                [$pattern]
            );
            foreach ($shows as &$w) {
                $w['url'] = '/library/shows/' . rawurlencode($w['name']);
            }
            unset($w);

            return array_merge($movies, $shows);
        }

        return [];
    }
}
