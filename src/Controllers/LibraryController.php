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
    private const VALID_TYPES = ['movies', 'shows', 'music', 'audiobooks', 'books'];

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

        // Group shows by show_name, music by author, audiobooks by book_name, everything else flat
        [$items, $total, $grouped] = match ($type) {
            'shows'      => $this->browseShows($offset, $limit),
            'music'      => $this->browseMusic($offset, $limit),
            'audiobooks' => $this->browseAudiobooks($offset, $limit),
            default      => $this->browseFlat($type, $offset, $limit),
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
        $dirPath   = $this->libraryPath . '/' . $type . '/' . $urlPath;
        $pathDepth = count(array_filter(explode('/', $urlPath)));

        if (is_dir($dirPath)) {
            // Grouped types get a metadata-rich detail page at their "entity" depth
            $isEntityDir = ($pathDepth === 1 && in_array($type, ['shows', 'music'], true))
                        || ($pathDepth >= 2 && $type === 'audiobooks');
            if ($isEntityDir) {
                return $this->entityDetail($response, $type, $urlPath, $dirPath);
            }
            $entries = $this->dirEntries($dirPath, $urlPath);
            $entries = $this->enrichEntriesWithMeta($entries, $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        // Otherwise treat as a file item
        $fullPath = realpath($dirPath);

        // File doesn't exist on disk — clean up its DB entry if present and 404
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

        // Write running state synchronously so the first poll sees scanning=true
        // before the background process has had a chance to start
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
        $filterType = isset($data['type']) && in_array($data['type'], self::VALID_TYPES, true) ? $data['type'] : null;

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
            'stats'           => [
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
        $type = $args['type'];
        if (!in_array($type, self::VALID_TYPES, true)) {
            return $response->withStatus(404);
        }

        $stateFile = dirname(__DIR__, 2) . '/storage/scan.json';
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true) ?? [];
            if ($state['running'] ?? false) {
                return $response->withHeader('Location', '/library/' . $type)->withStatus(302);
            }
        }

        // Force all items of this type to be re-enriched
        $this->db->execute('UPDATE media SET metadata_fetched_at = NULL WHERE type = ?', [$type]);

        $php    = PHP_BINARY;
        $script = realpath(dirname(__DIR__, 2) . '/bin/enrich.php');
        exec(sprintf('%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($type)
        ));

        return $response->withHeader('Location', '/library/' . $type)->withStatus(302);
    }

    private function entityDetail(Response $response, string $type, string $urlPath, string $dirPath): Response
    {
        $parts    = array_values(array_filter(explode('/', $urlPath)));
        // For audiobooks the entity key is always the last path segment (book or series name)
        $groupKey = $type === 'audiobooks' ? end($parts) : ($parts[0] ?? $urlPath);

        // Pull one indexed row to get metadata.
        // For audiobooks series pages: prefer the first book (lowest series_order) so the
        // series hero always shows book 1's cover/description rather than an arbitrary entry.
        // For everything else: prefer the most recently enriched row.
        $entity = $this->db->first(
            'SELECT * FROM media WHERE type = ? AND (show_name = ? OR author = ? OR book_name = ? OR series = ?)
             ORDER BY series_order ASC NULLS LAST, metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$type, $groupKey, $groupKey, $groupKey, $groupKey]
        );

        // Fallback for audiobook book directories where book_name is the M4B filename,
        // not the directory name — search for any file inside this directory.
        if (!$entity && $type === 'audiobooks') {
            $entity = $this->db->first(
                'SELECT * FROM media WHERE type = ? AND path LIKE ?
                 ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
                [$type, $dirPath . '/%']
            );
        }

        if (!$entity) {
            $entries = $this->enrichEntriesWithMeta($this->dirEntries($dirPath, $urlPath), $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        $meta          = json_decode($entity['metadata'] ?? '{}', true) ?? [];
        $seasonPosters = $meta['season_posters'] ?? [];
        $entries       = $this->dirEntries($dirPath, $urlPath);

        $audiobookSection = null;
        if ($type === 'audiobooks') {
            $hasDirs = !empty(array_filter($entries, fn($e) => $e['is_dir']));
            // M4Bs at depth 2 (Author/Series) are individual books; deeper means we're
            // inside a book dir and the M4Bs are playable files for that one book.
            $pathDepth = count($parts);
            $hasM4Bs   = !$hasDirs && $pathDepth <= 2 && !empty(array_filter(
                $entries, fn($e) => !$e['is_dir'] && strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)) === 'm4b'
            ));

            // Detect series dirs containing single-file non-M4B books (e.g. full-book MP3/AAC).
            // Sample the first audio file; if it has a series set it's a book, not a chapter.
            $hasSeriesFiles = false;
            if (!$hasDirs && !$hasM4Bs) {
                $audioFiles = array_values(array_filter($entries, fn($e) => !$e['is_dir']));
                if (!empty($audioFiles)) {
                    $probe = $this->db->first(
                        'SELECT series FROM media WHERE path = ?',
                        [$dirPath . '/' . $audioFiles[0]['name']]
                    );
                    $hasSeriesFiles = !empty($probe['series']) && $pathDepth <= 2;
                }
            }

            if ($hasDirs || $hasM4Bs || $hasSeriesFiles) {
                // Books view: each entry is a self-contained book (dir with chapters, M4B, or full-audio file).
                // Use path-based lookup so book_name mismatches (Audible IDs etc.) don't break it.
                $audiobookSection = 'books';
                foreach ($entries as &$entry) {
                    if ($entry['is_dir']) {
                        // Any file inside this subdirectory represents this book
                        $bookRow = $this->db->first(
                            'SELECT id, poster, title, book_name, series_order, metadata_fetched_at FROM media
                             WHERE type = "audiobooks" AND path LIKE ?
                             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
                            [$dirPath . '/' . $entry['name'] . '/%']
                        );
                        // If title == book_name the metadata was never properly enriched (still the raw
                        // M4B filename). The directory name is a much cleaner display label in that case.
                        $properTitle = ($bookRow && $bookRow['title'] && $bookRow['title'] !== $bookRow['book_name'])
                            ? $bookRow['title'] : null;
                        $entry['title'] = $properTitle ?? $entry['name'];
                    } else {
                        // Single-file book (M4B or full-audio) at series level: look up by exact path
                        $bookRow = $this->db->first(
                            'SELECT id, poster, title, book_name, series_order, metadata_fetched_at FROM media WHERE path = ? LIMIT 1',
                            [$dirPath . '/' . $entry['name']]
                        );
                        $entry['title'] = ($bookRow && $bookRow['title'])
                            ? $bookRow['title']
                            : pathinfo($entry['name'], PATHINFO_FILENAME);
                    }
                    if ($bookRow) {
                        $entry['id']                  = $bookRow['id'];
                        $entry['poster']              = $bookRow['poster'];
                        $entry['series_order']        = $bookRow['series_order'] ?? null;
                        $entry['metadata_fetched_at'] = $bookRow['metadata_fetched_at'] ?? null;
                    }
                }
                unset($entry);

                // Sort books by series_order (nulls last), then alphabetically
                usort($entries, function (array $a, array $b): int {
                    $ao = isset($a['series_order']) ? (float) $a['series_order'] : null;
                    $bo = isset($b['series_order']) ? (float) $b['series_order'] : null;
                    if ($ao === null && $bo === null) {
                        return strnatcasecmp($a['title'] ?? $a['name'], $b['title'] ?? $b['name']);
                    }
                    if ($ao === null) return 1;
                    if ($bo === null) return -1;
                    return $ao <=> $bo;
                });
            } else {
                // Chapters or book-level M4B files: render as playable entries with duration
                $audiobookSection = 'chapters';
                $entries = $this->enrichEntriesWithMeta($entries, $type, true);
            }
        } else {
            // Shows/music: tag season/album directory entries with their poster
            foreach ($entries as &$entry) {
                if ($entry['is_dir'] && preg_match('/(\d+)/', $entry['name'], $m)) {
                    $entry['poster'] = $seasonPosters[(int) $m[1]] ?? null;
                }
            }
            unset($entry);
        }

        // For audiobook series pages, load series-level metadata separately
        $seriesEntity     = null;
        $seriesMetaDecoded = null;
        if ($type === 'audiobooks' && $audiobookSection === 'books') {
            $seriesAuthor = $entity['author'] ?? null;
            if (!$seriesAuthor) {
                $authorRow = $this->db->first(
                    'SELECT author FROM media WHERE type = "audiobooks" AND series = ? AND author IS NOT NULL LIMIT 1',
                    [$groupKey]
                );
                $seriesAuthor = $authorRow['author'] ?? null;
            }
            if ($seriesAuthor) {
                $seriesEntity = $this->db->first(
                    'SELECT * FROM series_meta WHERE series = ? AND author = ? LIMIT 1',
                    [$groupKey, $seriesAuthor]
                );
                if ($seriesEntity) {
                    $seriesMetaDecoded = json_decode($seriesEntity['metadata'] ?? '{}', true) ?? [];
                }
            }
        }

        $html = $this->twig->render('library/entity_detail.html.twig', [
            'type'              => $type,
            'name'              => $groupKey,
            'entity'            => $entity,
            'meta'              => $meta,
            'entries'           => $entries,
            'audiobook_section' => $audiobookSection,
            'series_entity'     => $seriesEntity,
            'series_meta'       => $seriesMetaDecoded,
            'is_series'         => $seriesEntity !== null,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

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
        // Drop groups whose directory no longer exists before counting/displaying
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
            'SELECT author, COUNT(*) as track_count, MAX(poster) as poster,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media WHERE type = "music" AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT author) as n FROM media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    /**
     * For shows/music: if the top-level group directory (Author/, ShowName/) no longer
     * exists on disk, delete all DB entries for that group. Checks only distinct group
     * names — one directory check per group, not per file.
     */
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

    private function browseAudiobooks(int $offset, int $limit): array
    {
        $prefix = $this->libraryPath . '/audiobooks/';

        // Prune audiobook entries whose sample file no longer exists on disk.
        // One check per distinct book_name (not per file), so stays fast.
        $books = $this->db->query(
            'SELECT book_name, author, MIN(path) as sample_path
             FROM media WHERE type = "audiobooks" AND book_name IS NOT NULL
             GROUP BY book_name, author'
        );
        foreach ($books as $b) {
            // For chapter-file books the sample is inside a dir; for M4Bs it IS the file.
            $sample = $b['sample_path'];
            if (!file_exists($sample) && !is_dir(dirname($sample))) {
                $this->db->execute(
                    'DELETE FROM media WHERE type = "audiobooks" AND book_name = ? AND author = ?',
                    [$b['book_name'], $b['author']]
                );
            }
        }

        // Series: books that belong to a named series — group at the series level
        $seriesRows = $this->db->query(
            'SELECT "series" as item_type, series as name, author,
                    COUNT(DISTINCT book_name) as book_count, MAX(poster) as poster,
                    SUM(duration) as total_duration,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media WHERE type = "audiobooks" AND series IS NOT NULL AND book_name IS NOT NULL
             GROUP BY series, author'
        );
        foreach ($seriesRows as &$s) {
            // Series directory is always author/series-name in the filesystem
            $s['url_path'] = $s['author'] . '/' . $s['name'];
        }
        unset($s);

        // Standalone books: no series affiliation
        $bookRows = $this->db->query(
            'SELECT "book" as item_type, book_name as name, author,
                    COUNT(*) as file_count, MAX(poster) as poster,
                    MIN(extension) as ext, MIN(path) as sample_path,
                    SUM(duration) as total_duration,
                    SUM(CASE WHEN metadata_fetched_at IS NULL THEN 1 ELSE 0 END) as pending_meta
             FROM media WHERE type = "audiobooks" AND book_name IS NOT NULL AND series IS NULL
             GROUP BY book_name, author'
        );
        foreach ($bookRows as &$b) {
            $relative      = ltrim(str_replace($prefix, '', $b['sample_path']), '/');
            $b['url_path'] = $b['ext'] === 'm4b' ? $relative : dirname($relative);
        }
        unset($b);

        // Merge and sort alphabetically, then paginate in memory
        $all = array_merge($seriesRows, $bookRows);
        usort($all, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $total = count($all);
        $items = array_slice($all, $offset, $limit);

        return [$items, $total, true];
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

        if (!$filePaths) return $entries;

        $placeholders = implode(',', array_fill(0, count($filePaths), '?'));
        $rows         = $this->db->query(
            "SELECT id, path, title, poster, season, episode, duration FROM media WHERE path IN ($placeholders)",
            $filePaths
        );
        $metaByPath = [];
        foreach ($rows as $row) {
            $metaByPath[$row['path']] = $row;
        }

        // For shows, extract the show name from the first entry's path so we can strip it
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

    /**
     * Derive a human-readable chapter label from an audio filename.
     * Handles common patterns: "Chapter 01", "Part_03", "01 - Title", bare "01", etc.
     */
    private function chapterTitleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $s    = str_replace(['_', '.'], ' ', $base);
        $s    = trim(preg_replace('/\s{2,}/', ' ', $s));

        // Explicit "Chapter N" or "Part N" anywhere → normalise
        if (preg_match('/\b(chapter|part)\s+(\d+)\b/i', $s, $m)) {
            return ucfirst(strtolower($m[1])) . ' ' . (int) $m[2];
        }

        // Leading number with a separator and trailing title: "01 - The Council of Elrond"
        if (preg_match('/^0*(\d+)\s*[-–]\s*(.+)$/', $s, $m)) {
            return $m[1] . ' – ' . $m[2];
        }

        // Bare leading number (possibly padded): "01", "007"
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
            // Already a clean enriched title — just strip show name prefix if present
            return $showName ? $this->stripShowNamePrefix($title, $showName) : $title;
        }

        // Strip S##E## episode code
        $clean = preg_replace('/\b[Ss]\d{1,2}[Ee]\d{1,3}\b[\s._-]*/', '', $title);
        // Replace dots and underscores used as word separators
        $clean = str_replace(['.', '_'], ' ', $clean);
        // Strip quality/release tags and everything that follows
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
            if ($entry === '.' || $entry === '..' || $entry === '.DS_Store') continue;
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
