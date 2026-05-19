<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\LibraryScanner;
use App\Services\Metadata\MetadataService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class LibraryController
{
    private const VALID_TYPES = ['movies', 'shows', 'music', 'books'];

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $db,
        private readonly LibraryScanner $scanner,
        private readonly MetadataService $metadata,
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

        [$items, $total, $grouped] = match ($type) {
            'shows'  => $this->browseShows($offset, $limit),
            'music'  => $this->browseMusic($offset, $limit),
            'books'  => $this->browseBooks($offset, $limit),
            default  => $this->browseFlat($type, $offset, $limit),
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
        $type    = $args['type'];
        $urlPath = $args['path'] ?? '';

        if (!in_array($type, self::VALID_TYPES, true)) {
            return $response->withStatus(404);
        }

        if ($type === 'books') {
            return $this->handleBooksItem($response, $urlPath);
        }

        // Check if this is a directory-level browse (show/season/artist drill-down)
        $dirPath   = $this->libraryPath . '/' . $type . '/' . $urlPath;
        $pathDepth = count(array_filter(explode('/', $urlPath)));

        if (is_dir($dirPath)) {
            $isEntityDir = ($pathDepth === 1 && in_array($type, ['shows', 'music'], true));
            if ($isEntityDir) {
                return $this->entityDetail($response, $type, $urlPath, $dirPath);
            }
            $entries = $this->dirEntries($dirPath, $urlPath);
            $entries = $this->enrichEntriesWithMeta($entries, $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        // File item
        $fullPath = realpath($dirPath);

        if ($fullPath === false || !file_exists($fullPath)) {
            if ($fullPath) {
                $this->db->execute('DELETE FROM media WHERE path = ?', [$fullPath]);
            }
            return $response->withStatus(404);
        }

        $item = $this->db->first('SELECT * FROM media WHERE path = ?', [$fullPath]);

        if (!$item) {
            return $response->withStatus(404);
        }

        $item['stream_url'] = '/stream/' . $type . '/' . $urlPath;
        $item['title']      = $this->cleanFilenameTitle($item['title'] ?? null, $item['show_name'] ?? null) ?? $item['filename'];
        $item['quality']    = $this->extractQualityInfo($item['filename'] ?? '');

        $html = $this->twig->render('library/item.html.twig', [
            'item' => $item,
            'type' => $type,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    // ── Books hierarchy ──────────────────────────────────────────────────────

    /**
     * Route a books URL based on path depth and DB context.
     *
     * Structure on disk: Author/[Series/]Book/Version/files
     */
    private function handleBooksItem(Response $response, string $urlPath): Response
    {
        $dirPath = $this->libraryPath . '/books/' . $urlPath;

        // A file path — render item page directly
        if (is_file($dirPath)) {
            return $this->booksFileItem($response, $urlPath, $dirPath);
        }

        if (!is_dir($dirPath)) {
            return $response->withStatus(404);
        }

        $parts = array_values(array_filter(explode('/', $urlPath)));
        $depth = count($parts);

        return match (true) {
            $depth === 1 => $this->booksAuthorPage($response, $parts[0], $urlPath),
            $depth === 2 => $this->isBooksSeries($parts[0], $parts[1])
                ? $this->booksSeriesPage($response, $parts[0], $parts[1], $urlPath)
                : $this->booksBookPage($response, $parts[0], null, $parts[1], $urlPath),
            $depth === 3 => $this->isBooksSeries($parts[0], $parts[1])
                ? $this->booksBookPage($response, $parts[0], $parts[1], $parts[2], $urlPath)
                : $this->booksVersionPage($response, $parts[0], null, $parts[1], $parts[2], $urlPath),
            $depth === 4 => $this->booksVersionPage($response, $parts[0], $parts[1], $parts[2], $parts[3], $urlPath),
            default      => $this->browseDirectory($response, 'books', $urlPath, $this->dirEntries($dirPath, $urlPath)),
        };
    }

    /** True when Author/Name has DB entries as a series (not a book title). */
    private function isBooksSeries(string $author, string $name): bool
    {
        $count = (int) ($this->db->first(
            'SELECT COUNT(*) as n FROM media
             WHERE type IN ("books","audiobooks") AND author = ? AND series = ?',
            [$author, $name]
        )['n'] ?? 0);

        if ($count > 0) {
            return true;
        }

        // Filesystem fallback when DB is empty: a series dir contains subdirs
        // (books), each of which contains subdirs (versions).
        $path = $this->libraryPath . '/books/' . $author . '/' . $name;
        if (!is_dir($path)) {
            return false;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $path . '/' . $entry;
            if (is_dir($sub)) {
                foreach (scandir($sub) as $s) {
                    if ($s !== '.' && $s !== '..' && is_dir($sub . '/' . $s)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** /library/books/Author */
    private function booksAuthorPage(Response $response, string $author, string $urlPath): Response
    {
        $dirPath = $this->libraryPath . '/books/' . $urlPath;
        $entries = $this->dirEntries($dirPath, $urlPath);

        $person = $this->db->first(
            'SELECT id, slug, image, bio FROM people WHERE name = ? AND role = "author" LIMIT 1',
            [$author]
        );

        // Enrich directory entries with DB poster + item-type info
        $series = array_column(
            $this->db->query(
                'SELECT series as name, COUNT(DISTINCT book_name) as book_count, MAX(poster) as poster
                 FROM media WHERE type IN ("books","audiobooks") AND author = ? AND series IS NOT NULL
                 GROUP BY series',
                [$author]
            ),
            null, 'name'
        );
        $books = array_column(
            $this->db->query(
                'SELECT book_name as name, MAX(poster) as poster,
                        COUNT(DISTINCT book_version) as version_count
                 FROM media WHERE type IN ("books","audiobooks") AND author = ? AND series IS NULL
                   AND book_name IS NOT NULL
                 GROUP BY book_name',
                [$author]
            ),
            null, 'name'
        );

        foreach ($entries as &$entry) {
            if (!$entry['is_dir']) {
                continue;
            }
            $name = $entry['name'];
            if (isset($series[$name])) {
                $entry['poster']     = $series[$name]['poster'];
                $entry['item_type']  = 'series';
                $entry['book_count'] = $series[$name]['book_count'];
            } elseif (isset($books[$name])) {
                $entry['poster']        = $books[$name]['poster'];
                $entry['item_type']     = 'book';
                $entry['version_count'] = $books[$name]['version_count'];
            }
        }
        unset($entry);

        $html = $this->twig->render('library/books_detail.html.twig', [
            'type'    => 'books',
            'level'   => 'author',
            'author'  => $author,
            'series'  => null,
            'book'    => null,
            'entity'  => null,
            'person'  => $person,
            'entries' => $entries,
            'urlPath' => $urlPath,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /** /library/books/Author/Series */
    private function booksSeriesPage(Response $response, string $author, string $seriesName, string $urlPath): Response
    {
        $dirPath = $this->libraryPath . '/books/' . $urlPath;
        $entries = $this->dirEntries($dirPath, $urlPath);

        $bookRows = array_column(
            $this->db->query(
                'SELECT book_name as name, MAX(poster) as poster, MAX(year) as year,
                        MIN(series_order) as series_order
                 FROM media WHERE type IN ("books","audiobooks") AND author = ? AND series = ?
                   AND book_name IS NOT NULL
                 GROUP BY book_name ORDER BY series_order ASC NULLS LAST, book_name ASC',
                [$author, $seriesName]
            ),
            null, 'name'
        );

        foreach ($entries as &$entry) {
            if (!$entry['is_dir']) {
                continue;
            }
            if (isset($bookRows[$entry['name']])) {
                $b = $bookRows[$entry['name']];
                $entry['poster']       = $b['poster'];
                $entry['series_order'] = $b['series_order'];
                $entry['year']         = $b['year'];
            }
        }
        unset($entry);

        usort($entries, function (array $a, array $b): int {
            $ao = isset($a['series_order']) && $a['series_order'] !== null ? (float) $a['series_order'] : null;
            $bo = isset($b['series_order']) && $b['series_order'] !== null ? (float) $b['series_order'] : null;
            if ($ao === null && $bo === null) return strnatcasecmp($a['name'], $b['name']);
            if ($ao === null) return 1;
            if ($bo === null) return -1;
            return $ao <=> $bo;
        });

        $seriesMeta = $this->db->first(
            'SELECT * FROM series_meta WHERE series = ? AND author = ? LIMIT 1',
            [$seriesName, $author]
        );

        $html = $this->twig->render('library/books_detail.html.twig', [
            'type'        => 'books',
            'level'       => 'series',
            'author'      => $author,
            'series'      => $seriesName,
            'book'        => null,
            'entity'      => $seriesMeta,
            'entries'     => $entries,
            'urlPath'     => $urlPath,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /** /library/books/Author/[Series/]Book — shows available versions */
    private function booksBookPage(
        Response $response,
        string $author,
        ?string $seriesName,
        string $bookName,
        string $urlPath
    ): Response {
        $dirPath = $this->libraryPath . '/books/' . $urlPath;
        $entries = $this->dirEntries($dirPath, $urlPath);

        // Pull book metadata from DB (any version will have the same enriched data)
        $entity = $this->db->first(
            'SELECT * FROM media WHERE type IN ("books","audiobooks") AND author = ? AND book_name = ?
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$author, $bookName]
        );

        // Enrich version directory entries with file count + format type
        foreach ($entries as &$entry) {
            if (!$entry['is_dir']) {
                continue;
            }
            $versionPath = $dirPath . '/' . $entry['name'];
            $files = array_filter(
                array_diff(scandir($versionPath), ['.', '..', '.DS_Store']),
                fn($f) => is_file($versionPath . '/' . $f)
            );
            $entry['file_count'] = count($files);
            $firstFile = reset($files);
            if ($firstFile) {
                $ext = strtolower(pathinfo($firstFile, PATHINFO_EXTENSION));
                $entry['version_type'] = in_array($ext, ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'], true)
                    ? 'audio'
                    : 'ebook';
            }
        }
        unset($entry);

        $html = $this->twig->render('library/books_detail.html.twig', [
            'type'    => 'books',
            'level'   => 'book',
            'author'  => $author,
            'series'  => $seriesName,
            'book'    => $bookName,
            'entity'  => $entity,
            'entries' => $entries,
            'urlPath' => $urlPath,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /** /library/books/Author/[Series/]Book/Version — version detail page */
    private function booksVersionPage(
        Response $response,
        string $author,
        ?string $seriesName,
        string $bookName,
        string $versionName,
        string $urlPath
    ): Response {
        $dirPath     = $this->libraryPath . '/books/' . $urlPath;
        $entries     = $this->dirEntries($dirPath, $urlPath);
        $fileEntries = array_values(array_filter($entries, fn($e) => !$e['is_dir']));

        // Pull metadata from the first indexed file in this version directory
        $entity = $this->db->first(
            'SELECT * FROM media WHERE type IN ("books","audiobooks") AND path LIKE ?
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$dirPath . '/%']
        );

        // Single-file version → render as a full item page
        if (count($fileEntries) === 1) {
            $fileEntry = $fileEntries[0];
            $filePath  = realpath($dirPath . '/' . $fileEntry['name']);

            if (!$filePath || !file_exists($filePath)) {
                return $response->withStatus(404);
            }

            $item = $entity ?? $this->db->first('SELECT * FROM media WHERE path = ?', [$filePath]);
            if (!$item) {
                return $response->withStatus(404);
            }

            $item['stream_url'] = '/stream/books/' . $urlPath . '/' . $fileEntry['name'];
            $item['title']      = $item['title'] ?? $bookName;
            $item['quality']    = $this->extractQualityInfo($item['filename'] ?? '');

            $html = $this->twig->render('library/item.html.twig', [
                'item' => $item,
                'type' => 'books',
            ]);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html');
        }

        // Multi-file version (chapter audiobooks) → chapter list
        $fileEntries = $this->enrichEntriesWithMeta($fileEntries, 'books', true);

        $html = $this->twig->render('library/entity_detail.html.twig', [
            'type'              => 'books',
            'name'              => $bookName,
            'entity'            => $entity ?? [],
            'meta'              => json_decode($entity['metadata'] ?? '{}', true) ?? [],
            'entries'           => $fileEntries,
            'audiobook_section' => 'chapters',
            'series_entity'     => null,
            'series_meta'       => null,
            'is_series'         => false,
            'books_version'     => $versionName,
            'books_breadcrumb'  => $this->buildBooksBreadcrumb($author, $seriesName, $bookName, $versionName),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /** Render a single file within a version directory (chapter file direct link). */
    private function booksFileItem(Response $response, string $urlPath, string $filePath): Response
    {
        $realPath = realpath($filePath);
        if ($realPath === false || !file_exists($realPath)) {
            return $response->withStatus(404);
        }

        $item = $this->db->first('SELECT * FROM media WHERE path = ?', [$realPath]);
        if (!$item) {
            return $response->withStatus(404);
        }

        $item['stream_url'] = '/stream/books/' . $urlPath;
        $item['title']      = $item['title'] ?? $item['filename'];
        $item['quality']    = $this->extractQualityInfo($item['filename'] ?? '');

        $html = $this->twig->render('library/item.html.twig', [
            'item' => $item,
            'type' => 'books',
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function buildBooksBreadcrumb(string $author, ?string $series, string $book, ?string $version): array
    {
        $crumbs   = [];
        $basePath = '/library/books/' . rawurlencode($author);
        $crumbs[] = ['label' => $author, 'url' => $basePath];
        if ($series) {
            $basePath .= '/' . rawurlencode($series);
            $crumbs[] = ['label' => $series, 'url' => $basePath];
        }
        $basePath .= '/' . rawurlencode($book);
        $crumbs[] = ['label' => $book, 'url' => $basePath];
        if ($version) {
            $basePath .= '/' . rawurlencode($version);
            $crumbs[] = ['label' => $version, 'url' => $basePath];
        }
        return $crumbs;
    }

    // ── Scan / Status ────────────────────────────────────────────────────────

    public function scan(Request $request, Response $response): Response
    {
        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
            if ($state['running'] ?? false) {
                $response->getBody()->write(json_encode(['error' => 'Scan already running']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }
        }

        file_put_contents($stateFile, json_encode([
            'running'      => true,
            'phase'        => 'starting',
            'current_type' => null,
            'total'        => 0,
            'processed'    => 0,
            'added'        => 0,
            'updated'      => 0,
            'skipped'      => 0,
        ]), LOCK_EX);

        $body       = (string) $request->getBody();
        $data       = $body ? (json_decode($body, true) ?? []) : [];
        $allTypes   = array_merge(self::VALID_TYPES, ['audiobooks']);
        $filterType = isset($data['type']) && in_array($data['type'], $allTypes, true) ? $data['type'] : null;
        // Treat an explicit audiobooks scan as a books scan (consolidated directory)
        if ($filterType === 'audiobooks') {
            $filterType = 'books';
        }

        $php    = PHP_BINARY;
        $script = realpath(dirname(__DIR__, 2) . '/bin/scan.php');
        $cmd    = $filterType
            ? sprintf('%s %s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($script), escapeshellarg($filterType))
            : sprintf('%s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($script));
        exec($cmd);

        $response->getBody()->write(json_encode(['started' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function status(Request $request, Response $response): Response
    {
        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        $state     = [];

        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
        }

        $rows   = $this->db->query('SELECT type, COUNT(*) as count FROM media GROUP BY type');
        $counts = array_column($rows, 'count', 'type');

        $response->getBody()->write(json_encode([
            'scanning'          => (bool) ($state['running'] ?? false),
            'phase'             => $state['phase'] ?? null,
            'current_type'      => $state['current_type'] ?? null,
            'current_item_id'   => $state['current_item_id'] ?? null,
            'current_group'     => $state['current_group'] ?? null,
            'current_item_name' => $state['current_item_name'] ?? null,
            'total'             => (int) ($state['total'] ?? 0),
            'processed'         => (int) ($state['processed'] ?? 0),
            'type_totals'       => $state['type_totals'] ?? null,
            'finished_at'       => $state['finished_at'] ?? null,
            'stats'             => [
                'added'   => $state['added']   ?? 0,
                'updated' => $state['updated'] ?? 0,
                'skipped' => $state['skipped'] ?? 0,
            ],
            'counts' => $counts,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refreshMetadata(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $item = $this->db->first('SELECT type, path FROM media WHERE id = ?', [$id]);

        if (!$item) {
            return $response->withStatus(404);
        }

        $this->metadata->enrichOne($id);

        $referer = $request->getHeaderLine('Referer');
        return $response
            ->withHeader('Location', $referer ?: '/')
            ->withStatus(302);
    }

    public function refreshSeriesMetadata(Request $request, Response $response): Response
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $series = trim($body['series'] ?? '');
        $author = trim($body['author'] ?? '');

        if (!$series || !$author) {
            return $response->withStatus(400);
        }

        $this->metadata->refreshSeriesMeta($series, $author);

        $referer = $request->getHeaderLine('Referer');
        return $response->withHeader('Location', $referer ?: '/')->withStatus(302);
    }

    public function refreshTypeMetadata(Request $request, Response $response, array $args): Response
    {
        $type     = $args['type'];
        $allTypes = array_merge(self::VALID_TYPES, ['audiobooks']);
        if (!in_array($type, $allTypes, true)) {
            return $response->withStatus(404);
        }

        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
            if ($state['running'] ?? false) {
                $target = in_array($type, self::VALID_TYPES, true) ? $type : 'books';
                return $response->withHeader('Location', '/library/' . $target)->withStatus(302);
            }
        }

        // Force re-enrichment for books → cover both books and audiobooks DB types
        if ($type === 'books') {
            $this->db->execute('UPDATE media SET metadata_fetched_at = NULL WHERE type IN ("books","audiobooks")');
        } else {
            $this->db->execute('UPDATE media SET metadata_fetched_at = NULL WHERE type = ?', [$type]);
        }

        $php    = PHP_BINARY;
        $script = realpath(dirname(__DIR__, 2) . '/bin/enrich.php');
        exec(sprintf('%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($type === 'audiobooks' ? 'books' : $type)
        ));

        $redirect = in_array($type, self::VALID_TYPES, true) ? $type : 'books';
        return $response->withHeader('Location', '/library/' . $redirect)->withStatus(302);
    }

    // ── Shows / Music / Books entity detail ─────────────────────────────────

    private function entityDetail(Response $response, string $type, string $urlPath, string $dirPath): Response
    {
        $parts    = array_values(array_filter(explode('/', $urlPath)));
        $groupKey = $parts[0] ?? $urlPath;

        $entity = $this->db->first(
            'SELECT * FROM media WHERE type = ? AND (show_name = ? OR author = ?)
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$type, $groupKey, $groupKey]
        );

        if (!$entity) {
            $entries = $this->enrichEntriesWithMeta($this->dirEntries($dirPath, $urlPath), $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        $meta          = json_decode($entity['metadata'] ?? '{}', true) ?? [];
        $seasonPosters = $meta['season_posters'] ?? [];
        $entries       = $this->dirEntries($dirPath, $urlPath);

        foreach ($entries as &$entry) {
            if ($entry['is_dir'] && preg_match('/(\d+)/', $entry['name'], $m)) {
                $entry['poster'] = $seasonPosters[(int) $m[1]] ?? null;
            }
        }
        unset($entry);

        // For music artists, prefer the artist photo from the people table
        $person = $type === 'music'
            ? $this->db->first(
                'SELECT id, slug, image, bio FROM people WHERE name = ? AND role = "artist" LIMIT 1',
                [$groupKey]
              )
            : null;

        $html = $this->twig->render('library/entity_detail.html.twig', [
            'type'              => $type,
            'name'              => $groupKey,
            'entity'            => $entity,
            'meta'              => $meta,
            'entries'           => $entries,
            'audiobook_section' => null,
            'series_entity'     => null,
            'series_meta'       => null,
            'is_series'         => false,
            'person'            => $person,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    // ── Browse helpers ───────────────────────────────────────────────────────

    private function browseFlat(string $type, int $offset, int $limit): array
    {
        $items  = $this->db->query(
            'SELECT * FROM media WHERE type = ? ORDER BY title LIMIT ? OFFSET ?',
            [$type, $limit, $offset]
        );
        $prefix  = $this->libraryPath . '/' . $type . '/';
        $missing = [];
        foreach ($items as &$item) {
            if (!file_exists($item['path'])) {
                $missing[] = $item['id'];
                continue;
            }
            $item['relative_path'] = str_replace($prefix, '', $item['path']);
            $item['title']         = $this->cleanFilenameTitle($item['title'] ?? null) ?? $item['title'];
        }
        unset($item);

        if ($missing) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $this->db->execute("DELETE FROM media WHERE id IN ($placeholders)", $missing);
            $items = array_values(array_filter($items, fn($i) => !in_array($i['id'], $missing, true)));
        }

        $total = $this->db->first('SELECT COUNT(*) as n FROM media WHERE type = ?', [$type]);
        return [$items, (int) ($total['n'] ?? 0), false];
    }

    private function browseShows(int $offset, int $limit): array
    {
        $this->pruneGroupsByDirectory('shows', 'show_name');

        $items = $this->db->query(
            'SELECT show_name, COUNT(*) as episode_count, MAX(poster) as poster,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media WHERE type = "shows" AND show_name IS NOT NULL
             GROUP BY show_name ORDER BY show_name LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT show_name) as n FROM media WHERE type = "shows"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseMusic(int $offset, int $limit): array
    {
        $this->pruneGroupsByDirectory('music', 'author');

        $items = $this->db->query(
            'SELECT author,
                    COUNT(*) as track_count,
                    COALESCE(
                        (SELECT image FROM people WHERE name = m.author AND role = \'artist\' AND image IS NOT NULL LIMIT 1),
                        MAX(poster)
                    ) as poster,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media m WHERE type = "music" AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT author) as n FROM media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseBooks(int $offset, int $limit): array
    {
        // Prune author groups whose directories no longer exist
        $groups = $this->db->query(
            'SELECT DISTINCT author as grp FROM media WHERE type IN ("books","audiobooks") AND author IS NOT NULL'
        );
        foreach ($groups as $row) {
            $dir = $this->libraryPath . '/books/' . $row['grp'];
            if (!is_dir($dir)) {
                $this->db->execute(
                    'DELETE FROM media WHERE type IN ("books","audiobooks") AND author = ?',
                    [$row['grp']]
                );
            }
        }

        $items = $this->db->query(
            'SELECT author,
                    COUNT(*) as item_count,
                    COALESCE(
                        (SELECT image FROM people WHERE name = m.author AND role = \'author\' AND image IS NOT NULL LIMIT 1),
                        MAX(poster)
                    ) as poster,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media m WHERE type IN ("books","audiobooks") AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first(
            'SELECT COUNT(DISTINCT author) as n FROM media WHERE type IN ("books","audiobooks") AND author IS NOT NULL'
        );
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function pruneGroupsByDirectory(string $type, string $col): void
    {
        $groups = $this->db->query(
            "SELECT DISTINCT $col as grp FROM media WHERE type = ?",
            [$type]
        );
        foreach ($groups as $row) {
            $dir = $this->libraryPath . '/' . $type . '/' . $row['grp'];
            if (!is_dir($dir)) {
                $this->db->execute("DELETE FROM media WHERE type = ? AND $col = ?", [$type, $row['grp']]);
            }
        }
    }

    private function browseDirectory(Response $response, string $type, string $urlPath, array $entries): Response
    {
        $html = $this->twig->render('library/directory.html.twig', [
            'type'    => $type,
            'path'    => $urlPath,
            'entries' => $entries,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function enrichEntriesWithMeta(array $entries, string $type, bool $chapterView = false): array
    {
        $filePaths = [];
        foreach ($entries as $entry) {
            if (!$entry['is_dir']) {
                $filePaths[] = realpath($this->libraryPath . '/' . $type . '/' . $entry['url_path']);
            }
        }

        if (!$filePaths) {
            return $entries;
        }

        $placeholders = implode(',', array_fill(0, count($filePaths), '?'));
        $rows         = $this->db->query(
            "SELECT id, path, title, poster, season, episode, duration FROM media WHERE path IN ($placeholders)",
            $filePaths
        );
        $metaByPath = array_column($rows, null, 'path');

        $showName = null;
        if ($type === 'shows' && !empty($entries)) {
            $parts    = explode('/', ltrim($entries[0]['url_path'] ?? '', '/'));
            $showName = $parts[0] ?: null;
        }

        foreach ($entries as &$entry) {
            if (!$entry['is_dir']) {
                $full = realpath($this->libraryPath . '/' . $type . '/' . $entry['url_path']);
                $m    = $metaByPath[$full] ?? null;
                if ($m) {
                    $entry['id']       = $m['id'];
                    $entry['title']    = $chapterView
                        ? $this->chapterTitleFromFilename($entry['name'])
                        : $this->cleanFilenameTitle($m['title'] ?? null, $showName);
                    $entry['poster']   = $m['poster'] ?? null;
                    $entry['season']   = $m['season'] ?? null;
                    $entry['episode']  = $m['episode'] ?? null;
                    $entry['duration'] = $m['duration'] ?? null;
                }
            }
        }
        unset($entry);

        return $entries;
    }

    private function chapterTitleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $s    = str_replace(['_', '.'], ' ', $base);
        $s    = trim(preg_replace('/\s{2,}/', ' ', $s));

        if (preg_match('/\b(chapter|part)\s+(\d+)\b/i', $s, $m)) {
            return ucfirst(strtolower($m[1])) . ' ' . (int) $m[2];
        }
        if (preg_match('/^0*(\d+)\s*[-–]\s*(.+)$/', $s, $m)) {
            return $m[1] . ' – ' . $m[2];
        }
        if (preg_match('/^0*(\d+)\s*$/', $s, $m)) {
            return 'Part ' . $m[1];
        }

        return $s;
    }

    private function cleanFilenameTitle(?string $title, ?string $showName = null): ?string
    {
        if (!$title) return null;

        $hasSxxExx = (bool) preg_match('/[Ss]\d{1,2}[Ee]\d{1,3}/', $title);
        $hasDots   = substr_count($title, '.') >= 2;

        if (!$hasSxxExx && !$hasDots) {
            return $showName ? $this->stripShowNamePrefix($title, $showName) : $title;
        }

        $clean = preg_replace('/\b[Ss]\d{1,2}[Ee]\d{1,3}\b[\s._-]*/', '', $title);
        $clean = str_replace(['.', '_'], ' ', $clean);
        $clean = preg_replace(
            '/\s+\b(480p|576p|720p|1080p|2160p|4K|UHD|BluRay|Blu-Ray|BDRip|BRRip|WEB[-.]?DL|WEBRip|HDTV|DVDRip|HDRip|x264|x265|H\.?264|H\.?265|HEVC|AVC|AAC|AC3|DTS|HDR|SDR|NF|AMZN|DSNP|REPACK|PROPER|EXTENDED|UNRATED|THEATRICAL|REMUX)\b.*$/i',
            '',
            $clean
        );
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean));

        if ($showName) {
            $clean = $this->stripShowNamePrefix($clean, $showName);
        }

        return $clean !== '' ? $clean : $title;
    }

    private function stripShowNamePrefix(string $title, string $showName): string
    {
        $len = strlen($showName);
        if (strlen($title) > $len && strncasecmp($title, $showName, $len) === 0) {
            $rest = ltrim(substr($title, $len), " -_.");
            if ($rest !== '') return $rest;
        }
        return $title;
    }

    private function extractQualityInfo(string $filename): array
    {
        $info = [];

        if (preg_match('/\b(4K|2160p|1080p|720p|576p|480p)\b/i', $filename, $m)) {
            $info['resolution'] = strcasecmp($m[1], '2160p') === 0 ? '4K / 2160p' : $m[1];
        }
        if (preg_match('/\b(REMUX|BluRay|Blu-Ray|BDRip|BRRip|WEB[-.]?DL|WEBRip|HDTV|DVDRip|HDRip)\b/i', $filename, $m)) {
            $map = [
                'remux'  => 'Blu-ray Remux', 'bluray' => 'Blu-ray', 'blu-ray' => 'Blu-ray',
                'bdrip'  => 'BDRip',         'brrip'  => 'BRRip',
                'webdl'  => 'WEB-DL',        'web-dl' => 'WEB-DL', 'webrip' => 'WEBRip',
                'hdtv'   => 'HDTV',          'dvdrip' => 'DVDRip', 'hdrip'  => 'HDRip',
            ];
            $key              = strtolower(str_replace('.', '', $m[1]));
            $info['source']   = $map[$key] ?? $m[1];
        }
        if (preg_match('/\b(HDR10\+|HDR10|DV|Dolby\.?Vision|HDR)\b/i', $filename, $m)) {
            $info['hdr'] = $m[1];
        }
        if (preg_match('/\b(x265|x264|H\.265|H\.264|HEVC|AVC|AV1)\b/i', $filename, $m)) {
            $map = [
                'x265' => 'x265 (HEVC)', 'h.265' => 'x265 (HEVC)', 'hevc' => 'HEVC',
                'x264' => 'x264 (H.264)', 'h.264' => 'x264 (H.264)', 'avc' => 'H.264',
                'av1'  => 'AV1',
            ];
            $key           = strtolower($m[1]);
            $info['codec'] = $map[$key] ?? $m[1];
        }
        if (preg_match('/\b(DTS-HD|TrueHD|Atmos|DTS|EAC3|AC3|AAC|FLAC|MP3)\b/i', $filename, $m)) {
            $info['audio'] = $m[1];
        }

        return $info;
    }

    private function dirEntries(string $dirPath, string $urlPath): array
    {
        $entries = [];
        foreach (scandir($dirPath) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.DS_Store' || $entry[0] === '.') {
                continue;
            }
            $fullEntry = $dirPath . '/' . $entry;
            $entries[] = [
                'name'     => $entry,
                'is_dir'   => is_dir($fullEntry),
                'url_path' => $urlPath . '/' . $entry,
                'size'     => is_file($fullEntry) ? filesize($fullEntry) : null,
            ];
        }
        usort($entries, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strnatcasecmp($a['name'], $b['name']));
        return $entries;
    }
}
