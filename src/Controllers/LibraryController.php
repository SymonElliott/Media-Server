<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\AppLogger;
use App\Services\LibraryScanner;
use App\Services\Metadata\MetadataService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class LibraryController
{
    private const VALID_TYPES = ['movies', 'shows', 'music', 'books'];

    public function __construct(
        private readonly Environment     $twig,
        private readonly Connection      $db,
        private readonly LibraryScanner  $scanner,
        private readonly MetadataService $metadata,
        private readonly string          $libraryPath,
        private readonly AppLogger       $log,
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
        $view   = $params['view'] ?? null;
        $limit  = 60;
        $offset = ($page - 1) * $limit;

        [$items, $total, $grouped] = match (true) {
            $type === 'music' && $view === 'songs'   => $this->browseSongs($offset, $limit),
            $type === 'music' && $view === 'albums'  => $this->browseAlbums($offset, $limit),
            $type === 'books' && $view === 'books'   => $this->browseAllBooks($offset, $limit),
            $type === 'books' && $view === 'series'  => $this->browseSeries($offset, $limit),
            $type === 'music'                        => $this->browseMusic($offset, $limit),
            $type === 'books'                        => $this->browseBooks($offset, $limit),
            $type === 'shows'                        => $this->browseShows($offset, $limit),
            default                                  => $this->browseFlat($type, $offset, $limit),
        };

        $html = $this->twig->render('library/browse.html.twig', [
            'type'    => $type,
            'items'   => $items,
            'grouped' => $grouped,
            'view'    => $view,
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
            $isMusicAlbum = $type === 'music' && $pathDepth === 2;
            $isEntityDir  = ($pathDepth === 1 && in_array($type, ['shows', 'music'], true));
            if ($isMusicAlbum) {
                return $this->musicAlbumDetail($response, $urlPath, $dirPath);
            }
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

        $item = $this->db->first('SELECT * FROM v_media WHERE path = ?', [$fullPath]);

        if (!$item) {
            return $response->withStatus(404);
        }

        $readerExts         = ['epub', 'pdf', 'mobi', 'azw', 'azw3'];
        $ext                = strtolower($item['extension'] ?? '');
        $item['stream_url'] = '/stream/' . $type . '/' . $urlPath;
        $item['reader_url'] = in_array($ext, $readerExts, true) ? '/read/' . $type . '/' . $urlPath : null;
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

        $parts = array_values(array_filter(explode('/', $urlPath)));
        $depth = count($parts);

        // Route by depth + DB — directories no longer need to exist for series/book pages.
        return match (true) {
            $depth === 1 => $this->booksAuthorPage($response, $parts[0], $urlPath),
            $depth === 2 => $this->isBooksSeries($parts[0], $parts[1])
                ? $this->booksSeriesPage($response, $parts[0], $parts[1], $urlPath)
                : $this->booksBookPage($response, $parts[0], null, $parts[1], $urlPath),
            $depth === 3 => $this->isBooksSeries($parts[0], $parts[1])
                ? $this->booksBookPage($response, $parts[0], $parts[1], $parts[2], $urlPath)
                : $this->booksVersionPage($response, $parts[0], null, $parts[1], $parts[2], $urlPath),
            $depth === 4 => $this->booksVersionPage($response, $parts[0], $parts[1], $parts[2], $parts[3], $urlPath),
            default      => is_dir($dirPath)
                ? $this->browseDirectory($response, 'books', $urlPath, $this->dirEntries($dirPath, $urlPath))
                : $response->withStatus(404),
        };
    }

    /** True when the DB has books tagged with series = $name for this author. */
    private function isBooksSeries(string $author, string $name): bool
    {
        return (int) ($this->db->first(
            'SELECT COUNT(*) as n FROM v_media
             WHERE type IN ("books","audiobooks") AND author = ? AND series = ?',
            [$author, $name]
        )['n'] ?? 0) > 0;
    }

    /** /library/books/Author */
    private function booksAuthorPage(Response $response, string $author, string $urlPath): Response
    {
        $person = $this->db->first(
            'SELECT id, slug, image, bio FROM people WHERE name = ? AND role = "author" LIMIT 1',
            [$author]
        );

        $seriesRows = $this->db->query(
            'SELECT series as name, COUNT(DISTINCT book_name) as book_count, MAX(poster) as poster
             FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND series IS NOT NULL
             GROUP BY series ORDER BY series',
            [$author]
        );

        $bookRows = $this->db->query(
            'SELECT book_name as name, MAX(poster) as poster,
                    COUNT(DISTINCT book_version) as version_count
             FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND series IS NULL
               AND book_name IS NOT NULL
             GROUP BY book_name ORDER BY book_name',
            [$author]
        );

        $entries = [];
        foreach ($seriesRows as $s) {
            $entries[] = [
                'name'       => $s['name'],
                'is_dir'     => true,
                'item_type'  => 'series',
                'poster'     => $s['poster'],
                'book_count' => $s['book_count'],
                'url_path'   => rawurlencode($author) . '/' . rawurlencode($s['name']),
            ];
        }
        foreach ($bookRows as $b) {
            $entries[] = [
                'name'          => $b['name'],
                'is_dir'        => true,
                'item_type'     => 'book',
                'poster'        => $b['poster'],
                'version_count' => $b['version_count'],
                'url_path'      => rawurlencode($author) . '/' . rawurlencode($b['name']),
            ];
        }

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
        $bookRows = $this->db->query(
            'SELECT book_name as name, MAX(poster) as poster, MAX(year) as year,
                    MIN(series_order) as series_order
             FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND series = ?
               AND book_name IS NOT NULL
             GROUP BY book_name ORDER BY series_order ASC NULLS LAST, book_name ASC',
            [$author, $seriesName]
        );

        $entries = [];
        foreach ($bookRows as $b) {
            $entries[] = [
                'name'         => $b['name'],
                'is_dir'       => true,
                'item_type'    => 'book',
                'poster'       => $b['poster'],
                'series_order' => $b['series_order'],
                'year'         => $b['year'],
                'url_path'     => rawurlencode($author) . '/' . rawurlencode($b['name']),
            ];
        }

        $seriesMeta = $this->db->first(
            'SELECT * FROM series_meta WHERE series = ? AND author = ? LIMIT 1',
            [$seriesName, $author]
        );

        $standaloneBook = $this->db->first(
            'SELECT * FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND book_name = ? AND series IS NULL LIMIT 1',
            [$author, $seriesName]
        );

        $html = $this->twig->render('library/books_detail.html.twig', [
            'type'            => 'books',
            'level'           => 'series',
            'author'          => $author,
            'series'          => $seriesName,
            'book'            => null,
            'entity'          => $seriesMeta,
            'entries'         => $entries,
            'urlPath'         => $urlPath,
            'standalone_book' => $standaloneBook,
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

        // When the URL-derived path doesn't exist (flat layout), find the real directory via DB.
        if (!is_dir($dirPath)) {
            $sampleRow = $this->db->first(
                'SELECT path FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND book_name = ?
                 ORDER BY book_version IS NULL DESC, path ASC LIMIT 1',
                [$author, $bookName]
            );
            if ($sampleRow) {
                $realDir = dirname($sampleRow['path']);
                $relDir  = substr($realDir, strlen($this->libraryPath . '/books/'));
                $dirPath = $realDir;
                $urlPath = $relDir;
            }
        }

        $rawScan = is_dir($dirPath) ? $this->dirEntries($dirPath, $urlPath) : [];

        // Pull book metadata from DB (any version will have the same enriched data)
        $entity = $this->db->first(
            'SELECT * FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND book_name = ?
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$author, $bookName]
        );

        // Batch-load DB rows for all files under this book directory
        $dbRows = $this->db->query(
            'SELECT id, path, title FROM v_media WHERE type IN ("books","audiobooks") AND path LIKE ?',
            [$dirPath . '/%']
        );
        $dbByPath = [];
        foreach ($dbRows as $row) {
            $dbByPath[$row['path']] = $row;
        }

        $audioExts = ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'];
        $ebookExts = ['epub', 'pdf', 'mobi', 'azw', 'azw3', 'cbr', 'cbz'];

        $entries = [];
        foreach ($rawScan as $raw) {
            if ($raw['is_dir']) {
                // Narrator / chapter folder — must contain audio files to count
                $subPath  = $dirPath . '/' . $raw['name'];
                $subFiles = array_values(array_filter(
                    array_diff(scandir($subPath), ['.', '..', '.DS_Store']),
                    fn($f) => is_file($subPath . '/' . $f)
                ));
                if (empty($subFiles)) {
                    continue;
                }
                $firstExt = strtolower(pathinfo($subFiles[0], PATHINFO_EXTENSION));
                if (!in_array($firstExt, $audioExts, true)) {
                    continue; // not an audio narrator dir
                }
                $firstDbRow = null;
                foreach ($subFiles as $f) {
                    $row = $dbByPath[$subPath . '/' . $f] ?? null;
                    if ($row) { $firstDbRow = $row; break; }
                }
                $entries[] = [
                    'name'         => $raw['name'],
                    'is_dir'       => true,
                    'version_type' => 'audio',
                    'url_path'     => $raw['url_path'],
                    'file_count'   => count($subFiles),
                    'first_db_id'  => $firstDbRow['id'] ?? null,
                    'files'        => [],
                ];
            } else {
                // Direct file — classify by extension
                $ext = strtolower(pathinfo($raw['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $ebookExts, true)) {
                    $dbRow   = $dbByPath[$dirPath . '/' . $raw['name']] ?? null;
                    $entries[] = [
                        'name'         => $raw['name'],
                        'is_dir'       => false,
                        'version_type' => 'ebook',
                        'url_path'     => $raw['url_path'],
                        'file_count'   => 1,
                        'first_db_id'  => $dbRow['id'] ?? null,
                        'files'        => [[
                            'name'      => $raw['name'],
                            'stem'      => $dbRow['title'] ?? pathinfo($raw['name'], PATHINFO_FILENAME),
                            'extension' => $ext,
                            'url_path'  => $raw['url_path'],
                            'db_id'     => $dbRow['id'] ?? null,
                        ]],
                    ];
                } elseif (in_array($ext, $audioExts, true)) {
                    $dbRow   = $dbByPath[$dirPath . '/' . $raw['name']] ?? null;
                    $entries[] = [
                        'name'         => $dbRow['title'] ?? pathinfo($raw['name'], PATHINFO_FILENAME),
                        'is_dir'       => false,
                        'version_type' => 'audio',
                        'url_path'     => $raw['url_path'],
                        'file_count'   => 1,
                        'first_db_id'  => $dbRow['id'] ?? null,
                        'files'        => [],
                        'extension'    => $ext,
                    ];
                }
                // Skip unrecognised extensions (cover images, etc.)
            }
        }

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
            'SELECT * FROM v_media WHERE type IN ("books","audiobooks") AND path LIKE ?
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

            $item = $entity ?? $this->db->first('SELECT * FROM v_media WHERE path = ?', [$filePath]);
            if (!$item) {
                return $response->withStatus(404);
            }

            $readerExts         = ['epub', 'pdf', 'mobi', 'azw', 'azw3'];
            $fileExt            = strtolower($item['extension'] ?? '');
            $fileUrlPath        = $urlPath . '/' . $fileEntry['name'];
            $item['stream_url'] = '/stream/books/' . $fileUrlPath;
            $item['reader_url'] = in_array($fileExt, $readerExts, true) ? '/read/books/' . $fileUrlPath : null;
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

        $item = $this->db->first('SELECT * FROM v_media WHERE path = ?', [$realPath]);
        if (!$item) {
            return $response->withStatus(404);
        }

        $readerExts         = ['epub', 'pdf', 'mobi', 'azw', 'azw3'];
        $fileExt            = strtolower($item['extension'] ?? '');
        $item['stream_url'] = '/stream/books/' . $urlPath;
        $item['reader_url'] = in_array($fileExt, $readerExts, true) ? '/read/books/' . $urlPath : null;
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
                // Before rejecting, verify the recorded PID is actually alive.
                // If it's dead the state is stale (crash/kill) — auto-reset and allow restart.
                $pid   = (int) ($state['pid'] ?? 0);
                $alive = $pid > 0 && $this->pidIsAlive($pid);
                if ($alive) {
                    $response->getBody()->write(json_encode(['error' => 'Scan already running']));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
                // Stale lock — fall through to start a fresh scan
                $this->log->warn('scan', "Stale scan state detected (PID {$pid} is gone) — resetting");
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
        $filterGroup = ($filterType && isset($data['group']) && is_string($data['group']) && $data['group'] !== '')
            ? trim($data['group'])
            : null;

        $phpCli = $this->findPhpCli();
        $script = realpath(dirname(__DIR__, 2) . '/bin/scan.php');

        if (!$script || !is_readable($script)) {
            file_put_contents($stateFile, json_encode(['running' => false]), LOCK_EX);
            $response->getBody()->write(json_encode(['error' => 'scan.php not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $scriptArgs = array_values(array_filter([$filterType, $filterGroup], fn($v) => $v !== null));
        $cmd        = $this->buildBgCmd($phpCli, $script, $scriptArgs);
        exec($cmd);

        $scanDesc = match (true) {
            $filterGroup !== null => ucfirst($filterType ?? '') . ' › ' . $filterGroup,
            $filterType !== null  => ucfirst($filterType),
            default               => 'full library',
        };
        $this->log->info('scan', "Scan triggered ({$scanDesc}) by " . ($_SESSION['user']['username'] ?? 'system'));

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

        // Auto-recover: if the state says running but the PID is gone, clear it.
        // This handles SIGKILL, OOM kills, and crashes that skip the shutdown function.
        if (($state['running'] ?? false) && isset($state['pid'])) {
            $pid = (int) $state['pid'];
            if ($pid > 0 && !$this->pidIsAlive($pid)) {
                $state['running']     = false;
                $state['finished_at'] = $state['finished_at'] ?? date('c');
                $state['error']       = 'Scan process terminated unexpectedly';
                file_put_contents($stateFile, json_encode($state), LOCK_EX);
            }
        }

        $rows   = $this->db->query('SELECT type, COUNT(*) as count FROM media GROUP BY type');
        $counts = array_column($rows, 'count', 'type');

        $response->getBody()->write(json_encode([
            'scanning'          => (bool) ($state['running'] ?? false),
            'phase'             => $state['phase'] ?? null,
            'current_type'      => $state['current_type'] ?? null,
            'current_scan_name' => $state['current_scan_name'] ?? null,
            'current_item_id'   => $state['current_item_id'] ?? null,
            'current_group'     => $state['current_group'] ?? null,
            'current_item_name' => $state['current_item_name'] ?? null,
            'total'             => (int) ($state['total'] ?? 0),
            'processed'         => (int) ($state['processed'] ?? 0),
            'type_totals'       => $state['type_totals'] ?? null,
            'finished_at'       => $state['finished_at'] ?? null,
            'error'             => $state['error'] ?? null,
            'stats'             => [
                'added'   => $state['added']   ?? 0,
                'updated' => $state['updated'] ?? 0,
                'skipped' => $state['skipped'] ?? 0,
            ],
            'counts' => $counts,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function resetScan(Request $request, Response $response): Response
    {
        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        $state     = file_exists($stateFile)
            ? (json_decode(file_get_contents($stateFile), true) ?? [])
            : [];

        // Kill the background scan process if we recorded its PID.
        $pid = isset($state['pid']) ? (int) $state['pid'] : 0;
        if ($pid > 1 && ($state['running'] ?? false)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            } else {
                exec('kill -TERM ' . $pid . ' 2>/dev/null');
            }
        }

        file_put_contents($stateFile, json_encode(['running' => false]), LOCK_EX);
        $response->getBody()->write(json_encode(['reset' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logsPage(Request $request, Response $response): Response
    {
        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        $state     = [];
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
        }

        $html = $this->twig->render('logs.html.twig', [
            'scanning'    => (bool) ($state['running'] ?? false),
            'finished_at' => $state['finished_at'] ?? null,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function scanLog(Request $request, Response $response): Response
    {
        $logFile  = dirname(__DIR__, 2) . '/storage/scan.log';
        $offset   = max(0, (int) ($request->getQueryParams()['offset'] ?? 0));
        $text     = '';
        $size     = 0;

        if (file_exists($logFile)) {
            clearstatcache(true, $logFile);
            $size = filesize($logFile);
            if ($offset < $size) {
                $fh = fopen($logFile, 'r');
                if ($offset > 0) fseek($fh, $offset);
                $text = (string) fread($fh, $size - $offset);
                fclose($fh);
            }
        }

        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        $state     = file_exists($stateFile)
            ? (json_decode(file_get_contents($stateFile), true) ?? [])
            : [];

        // Sanitise: scan.log may contain non-UTF-8 bytes from metadata (author
        // names, titles from external APIs).  json_encode() returns false on
        // invalid UTF-8, which breaks the browser's JSON.parse().
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $response->getBody()->write(json_encode([
            'text'        => $text,
            'size'        => $size,
            'scanning'    => (bool) ($state['running'] ?? false),
            'finished_at' => $state['finished_at'] ?? null,
        ], JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function appLog(Request $request, Response $response): Response
    {
        $result = $this->log->tail(
            max(0, (int) ($request->getQueryParams()['offset'] ?? 0))
        );

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function clearAppLog(Request $request, Response $response): Response
    {
        $this->log->clear();
        $this->log->info('system', 'App log cleared by ' . ($_SESSION['user']['username'] ?? 'unknown'));
        $response->getBody()->write(json_encode(['cleared' => true]));
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
        $this->log->info('metadata', sprintf(
            'Refreshed metadata for id=%d (%s)',
            $id,
            $item['type']
        ));

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
        $this->log->info('metadata', "Refreshed series metadata: \"{$series}\" by {$author}");

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

        $script = realpath(dirname(__DIR__, 2) . '/bin/enrich.php');
        $cmd    = $this->buildBgCmd($this->findPhpCli(), $script, [$type === 'audiobooks' ? 'books' : $type]);
        exec($cmd);
        $this->log->info('metadata', "Bulk metadata refresh queued for {$type}");

        $redirect = in_array($type, self::VALID_TYPES, true) ? $type : 'books';
        return $response->withHeader('Location', '/library/' . $redirect)->withStatus(302);
    }

    // ── Shows / Music / Books entity detail ─────────────────────────────────

    private function entityDetail(Response $response, string $type, string $urlPath, string $dirPath): Response
    {
        $parts    = array_values(array_filter(explode('/', $urlPath)));
        $groupKey = $parts[0] ?? $urlPath;

        $entity = $this->db->first(
            'SELECT * FROM v_media WHERE type = ? AND (show_name = ? OR author = ?)
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

        // For music artists, attach album art from album_meta for each album directory
        if ($type === 'music') {
            $albumDirs = array_filter($entries, fn($e) => $e['is_dir']);
            if ($albumDirs) {
                $albumNames   = array_column($albumDirs, 'name');
                $placeholders = implode(',', array_fill(0, count($albumNames), '?'));
                $albumMetas   = $this->db->query(
                    "SELECT album, poster FROM album_meta WHERE artist = ? AND album IN ($placeholders) AND poster IS NOT NULL",
                    array_merge([$groupKey], $albumNames)
                );
                $posterMap = array_column($albumMetas, 'poster', 'album');
                foreach ($entries as &$entry) {
                    if ($entry['is_dir'] && isset($posterMap[$entry['name']])) {
                        $entry['poster'] = $posterMap[$entry['name']];
                    }
                }
                unset($entry);
            }
        }

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

    private function musicAlbumDetail(Response $response, string $urlPath, string $dirPath): Response
    {
        $parts  = array_values(array_filter(explode('/', $urlPath)));
        $artist = $parts[0] ?? '';
        $album  = $parts[1] ?? '';

        $albumMeta = $this->db->first(
            'SELECT * FROM album_meta WHERE album = ? AND artist = ?',
            [$album, $artist]
        );

        // Fall back to a representative media row for year/poster if album_meta not yet populated
        $entity = $this->db->first(
            'SELECT * FROM v_media WHERE type = "music" AND author = ? AND series = ?
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$artist, $album]
        );

        $entries = $this->enrichEntriesWithMeta($this->dirEntries($dirPath, $urlPath), 'music', false);

        $person = $this->db->first(
            'SELECT id, slug, image, bio FROM people WHERE name = ? AND role = \'artist\' LIMIT 1',
            [$artist]
        );

        $html = $this->twig->render('library/music_album.html.twig', [
            'artist'     => $artist,
            'album'      => $album,
            'album_meta' => $albumMeta,
            'entity'     => $entity,
            'entries'    => $entries,
            'person'     => $person,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function refreshAlbumMetadata(Request $request, Response $response): Response
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $album  = trim($body['album'] ?? '');
        $artist = trim($body['artist'] ?? '');

        if (!$album || !$artist) {
            return $response->withStatus(400);
        }

        $this->metadata->refreshAlbumMeta($album, $artist);

        $referer = $request->getHeaderLine('Referer');
        return $response->withHeader('Location', $referer ?: '/')->withStatus(302);
    }

    // ── Expandable children API ──────────────────────────────────────────────

    public function getChildren(Request $request, Response $response, array $args): Response
    {
        $type  = $args['type'];
        $path  = $args['path'] ?? '';
        $parts = array_values(array_filter(explode('/', $path)));

        $data = match ($type) {
            'shows' => $this->showSeasonChildren($parts),
            'music' => $this->musicAlbumChildren($parts),
            'books' => $this->booksSeriesChildren($parts),
            default => null,
        };

        if ($data === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function showSeasonChildren(array $parts): array
    {
        $showName  = $parts[0] ?? '';
        $seasonDir = $parts[1] ?? '';
        $season    = null;
        if (preg_match('/(\d+)/', $seasonDir, $m)) {
            $season = (int) $m[1];
        }
        if (!$showName || $season === null) {
            return [];
        }

        $prefix   = $this->libraryPath . '/shows/';
        $episodes = $this->db->query(
            'SELECT id, title, episode, path FROM v_media
             WHERE type = "shows" AND show_name = ? AND season = ?
             ORDER BY episode',
            [$showName, $season]
        );

        foreach ($episodes as &$ep) {
            $ep['url_path'] = ltrim(str_replace($prefix, '', $ep['path']), '/');
        }
        unset($ep);

        return array_values($episodes);
    }

    private function musicAlbumChildren(array $parts): array
    {
        $artist = $parts[0] ?? '';
        $album  = $parts[1] ?? '';
        if (!$artist || !$album) {
            return [];
        }

        $prefix = $this->libraryPath . '/music/';
        $tracks = $this->db->query(
            'SELECT id, title, series_order, path, extension FROM v_media
             WHERE type = "music" AND author = ? AND series = ?
             ORDER BY series_order, title',
            [$artist, $album]
        );

        foreach ($tracks as &$track) {
            $track['url_path'] = ltrim(str_replace($prefix, '', $track['path']), '/');
        }
        unset($track);

        return array_values($tracks);
    }

    private function booksSeriesChildren(array $parts): array
    {
        $author     = $parts[0] ?? '';
        $seriesName = $parts[1] ?? '';
        if (!$author || !$seriesName) {
            return [];
        }

        $books = $this->db->query(
            'SELECT book_name as name, MAX(poster) as poster, MAX(year) as year,
                    MIN(series_order) as series_order
             FROM v_media WHERE type IN ("books","audiobooks") AND author = ? AND series = ?
               AND book_name IS NOT NULL
             GROUP BY book_name ORDER BY series_order ASC NULLS LAST, book_name ASC',
            [$author, $seriesName]
        );

        foreach ($books as &$book) {
            $book['url_path'] = rawurlencode($author) . '/' . rawurlencode($book['name']);
        }
        unset($book);

        return array_values($books);
    }

    // ── Browse helpers ───────────────────────────────────────────────────────

    private function browseFlat(string $type, int $offset, int $limit): array
    {
        $items  = $this->db->query(
            'SELECT * FROM v_media WHERE type = ? ORDER BY title LIMIT ? OFFSET ?',
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
             FROM v_media WHERE type = "shows" AND show_name IS NOT NULL
             GROUP BY show_name ORDER BY show_name LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT show_name) as n FROM v_media WHERE type = "shows"');
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
             FROM v_media m WHERE type = "music" AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT author) as n FROM v_media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseBooks(int $offset, int $limit): array
    {
        // Prune author groups whose directories no longer exist.
        // Safety: skip pruning if the books library root is not accessible (NAS not mounted).
        if (is_dir($this->libraryPath . '/books')) {
            $groups = $this->db->query(
                'SELECT DISTINCT author as grp FROM v_media WHERE type IN ("books","audiobooks") AND author IS NOT NULL'
            );
            foreach ($groups as $row) {
                $dir = $this->libraryPath . '/books/' . $row['grp'];
                if (!is_dir($dir)) {
                    $this->db->execute(
                        'DELETE FROM media WHERE id IN (SELECT media_id FROM media_books WHERE author = ?)',
                        [$row['grp']]
                    );
                }
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
             FROM v_media m WHERE type IN ("books","audiobooks") AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first(
            'SELECT COUNT(DISTINCT author) as n FROM v_media WHERE type IN ("books","audiobooks") AND author IS NOT NULL'
        );
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseSongs(int $offset, int $limit): array
    {
        $prefix = $this->libraryPath . '/music/';
        $items  = $this->db->query(
            'SELECT m.id, m.title, m.author, m.series as album, m.year, m.duration, m.path, m.extension,
                    m.metadata_fetched_at, am.poster
             FROM v_media m
             LEFT JOIN album_meta am ON am.album = m.series AND am.artist = m.author
             WHERE m.type = "music"
             ORDER BY m.title ASC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        foreach ($items as &$item) {
            $item['relative_path'] = str_replace($prefix, '', $item['path']);
        }
        unset($item);
        $total = $this->db->first('SELECT COUNT(*) as n FROM media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), false];
    }

    private function browseAlbums(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT m.series as name, m.author,
                    am.poster, am.year,
                    COUNT(DISTINCT m.id) as track_count
             FROM v_media m
             LEFT JOIN album_meta am ON am.album = m.series AND am.artist = m.author
             WHERE m.type = \'music\' AND m.series IS NOT NULL AND m.author IS NOT NULL
             GROUP BY m.author, m.series
             ORDER BY m.series ASC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        foreach ($items as &$item) {
            $item['url_path'] = rawurlencode($item['author']) . '/' . rawurlencode($item['name']);
        }
        unset($item);
        $total = $this->db->first(
            'SELECT COUNT(*) as n FROM (
                SELECT DISTINCT author, series FROM v_media
                WHERE type = \'music\' AND series IS NOT NULL AND author IS NOT NULL
             )'
        );
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseSeries(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT series as name, MAX(author) as author,
                    MAX(poster) as poster, MAX(year) as year,
                    COUNT(DISTINCT id) as item_count
             FROM v_media
             WHERE type IN (\'books\', \'audiobooks\') AND series IS NOT NULL AND author IS NOT NULL
             GROUP BY author, series
             ORDER BY series ASC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        foreach ($items as &$item) {
            $item['url_path'] = rawurlencode($item['author']) . '/' . rawurlencode($item['name']);
        }
        unset($item);
        $total = $this->db->first(
            'SELECT COUNT(*) as n FROM (
                SELECT DISTINCT author, series FROM v_media
                WHERE type IN (\'books\', \'audiobooks\') AND series IS NOT NULL AND author IS NOT NULL
             )'
        );
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseAllBooks(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT book_name as name,
                    MAX(author) as author,
                    MAX(series) as series,
                    MAX(poster) as poster,
                    MAX(year) as year,
                    MAX(CASE WHEN type = \'audiobooks\' THEN 1 ELSE 0 END) as has_audio,
                    MAX(CASE WHEN type = \'books\'      THEN 1 ELSE 0 END) as has_ebook
             FROM v_media
             WHERE type IN ("books","audiobooks") AND book_name IS NOT NULL AND author IS NOT NULL
             GROUP BY author, book_name
             ORDER BY book_name ASC, author ASC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        foreach ($items as &$item) {
            $seriesSeg    = $item['series'] ? rawurlencode($item['series']) . '/' : '';
            $item['url_path'] = rawurlencode($item['author']) . '/' . $seriesSeg . rawurlencode($item['name']);
        }
        unset($item);
        $total = $this->db->first(
            'SELECT COUNT(*) as n FROM (
                SELECT DISTINCT author, book_name FROM v_media
                WHERE type IN ("books","audiobooks") AND book_name IS NOT NULL AND author IS NOT NULL
             )'
        );
        return [$items, (int) ($total['n'] ?? 0), false];
    }

    private function pruneGroupsByDirectory(string $type, string $col): void
    {
        // Safety: if the library root for this type is not accessible (e.g. NAS volume
        // not yet mounted after container restart), skip pruning entirely.
        // Without this guard every group's is_dir() check returns false and ALL media
        // for this type gets mass-deleted on the first browse request.
        if (!is_dir($this->libraryPath . '/' . $type)) {
            return;
        }

        $groups = $this->db->query(
            "SELECT DISTINCT $col as grp FROM v_media WHERE type = ?",
            [$type]
        );
        foreach ($groups as $row) {
            $dir = $this->libraryPath . '/' . $type . '/' . $row['grp'];
            if (!is_dir($dir)) {
                // Route DELETE through extension tables — $col is not on the base media table
                if ($col === 'show_name') {
                    $this->db->execute(
                        'DELETE FROM media WHERE id IN (SELECT media_id FROM media_shows WHERE show_name = ?)',
                        [$row['grp']]
                    );
                } else {
                    // 'author' (music) — maps to media_music.artist
                    $this->db->execute(
                        'DELETE FROM media WHERE id IN (SELECT media_id FROM media_music WHERE artist = ?)',
                        [$row['grp']]
                    );
                }
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
            "SELECT id, path, title, poster, season, episode, duration FROM v_media WHERE path IN ($placeholders)",
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

    // ── Ebook reader ─────────────────────────────────────────────────────────

    public function readItem(Request $request, Response $response, array $args): Response
    {
        $type    = $args['type'] ?? 'books';
        $urlPath = $args['path'] ?? '';

        if ($type !== 'books') {
            return $response->withStatus(404);
        }

        $filePath = realpath($this->libraryPath . '/books/' . $urlPath);

        if (!$filePath || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $item = $this->db->first('SELECT * FROM v_media WHERE path = ?', [$filePath]);

        if (!$item || !in_array($ext, ['epub', 'pdf', 'mobi', 'azw', 'azw3'], true)) {
            return $response->withStatus(404);
        }

        $item['stream_url'] = '/stream/books/' . $urlPath;

        $calibreAvailable = false;
        $convertUrl       = null;
        if (in_array($ext, ['mobi', 'azw', 'azw3'], true)) {
            $calibre = $this->findCalibre();
            if ($calibre) {
                $calibreAvailable = true;
                $convertUrl       = '/convert/books/' . $urlPath;
            }
        }

        // Back link: one level up from the file's URL path
        $parts   = array_filter(explode('/', $urlPath));
        array_pop($parts);
        $backUrl = '/library/books/' . implode('/', $parts);

        $html = $this->twig->render('library/reader.html.twig', [
            'item'             => $item,
            'ext'              => $ext,
            'backUrl'          => $backUrl,
            'calibreAvailable' => $calibreAvailable,
            'convertUrl'       => $convertUrl,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function convertItem(Request $request, Response $response, array $args): Response
    {
        $type    = $args['type'] ?? 'books';
        $urlPath = $args['path'] ?? '';

        if ($type !== 'books') {
            return $response->withStatus(404);
        }

        $filePath = realpath($this->libraryPath . '/books/' . $urlPath);
        if (!$filePath || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mobi', 'azw', 'azw3'], true)) {
            return $response->withStatus(400);
        }

        $calibre = $this->findCalibre();
        if (!$calibre) {
            $response->getBody()->write(json_encode(['error' => 'ebook-convert not found']));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }

        $cacheDir  = dirname(__DIR__, 2) . '/storage/converted';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/' . md5($filePath) . '.epub';

        if (!file_exists($cacheFile)) {
            $cmd = sprintf('%s %s %s 2>/dev/null',
                escapeshellarg($calibre),
                escapeshellarg($filePath),
                escapeshellarg($cacheFile)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !file_exists($cacheFile)) {
                $response->getBody()->write(json_encode(['error' => 'Conversion failed']));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
        }

        $size = filesize($cacheFile);
        $fh   = fopen($cacheFile, 'rb');
        $response->getBody()->write(stream_get_contents($fh));
        fclose($fh);

        return $response
            ->withHeader('Content-Type', 'application/epub+zip')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($cacheFile) . '"');
    }

    private function findCalibre(): ?string
    {
        foreach (['/usr/bin/ebook-convert', '/usr/local/bin/ebook-convert', '/opt/homebrew/bin/ebook-convert'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = trim((string) shell_exec('which ebook-convert 2>/dev/null'));
        return ($which && is_executable($which)) ? $which : null;
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

    // ── Process / background helpers ─────────────────────────────────────────

    /**
     * Return true if a process with the given PID is still running.
     * Uses posix_kill(pid, 0) on Linux (signal 0 = existence check, no signal sent).
     * Falls back to /proc/<pid> on systems without the POSIX extension.
     */
    private function pidIsAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return file_exists("/proc/{$pid}");
    }

    // ── Background-process helpers ────────────────────────────────────────────

    /**
     * Locate the CLI PHP binary.
     *
     * PHP_BINARY under PHP-FPM is the FPM daemon, not the CLI interpreter.
     * We try known install paths first and fall back to PHP_BINARY (which is
     * correct when running via the built-in server or CLI directly).
     */
    private function findPhpCli(): string
    {
        foreach ([
            '/usr/local/bin/php',       // Linux packages, old Homebrew
            '/opt/homebrew/bin/php',    // Homebrew on Apple Silicon / Intel
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return PHP_BINARY;
    }

    /**
     * Build a shell command that runs $script with $args in the background,
     * detached from the current process so it survives FPM worker recycling.
     *
     * - Linux / Docker : uses `setsid` (creates a new session)
     * - macOS dev      : uses `nohup` (`setsid` is not shipped with macOS)
     * - Fallback       : plain `&` (safe for the built-in PHP server)
     */
    private function buildBgCmd(string $phpBin, string $script, array $args = []): string
    {
        $detach = match (true) {
            is_executable('/usr/bin/setsid') => 'setsid',
            is_executable('/bin/setsid')     => 'setsid',
            is_executable('/usr/bin/nohup')  => 'nohup',
            is_executable('/bin/nohup')      => 'nohup',
            default                          => '',
        };

        $parts = array_map('escapeshellarg', array_merge([$phpBin, $script], $args));
        $base  = implode(' ', $parts) . ' > /dev/null 2>&1 &';

        return $detach !== '' ? "{$detach} {$base}" : $base;
    }
}
