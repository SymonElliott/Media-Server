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

        // Group shows by show_name, music by author, everything else flat
        [$items, $total, $grouped] = match ($type) {
            'shows'  => $this->browseShows($offset, $limit),
            'music'  => $this->browseMusic($offset, $limit),
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
        $type     = $args['type'];
        $urlPath  = $args['path'] ?? '';

        if (!in_array($type, self::VALID_TYPES, true)) {
            return $response->withStatus(404);
        }

        // Check if this is a directory-level browse (show/season/author drill-down)
        $dirPath   = $this->libraryPath . '/' . $type . '/' . $urlPath;
        $pathDepth = count(array_filter(explode('/', $urlPath)));

        if (is_dir($dirPath)) {
            // First-level directories for grouped types get a metadata-rich detail page
            if ($pathDepth === 1 && in_array($type, ['shows', 'music'], true)) {
                return $this->entityDetail($response, $type, $urlPath, $dirPath);
            }
            $entries = $this->dirEntries($dirPath, $urlPath);
            $entries = $this->enrichEntriesWithMeta($entries, $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        // Otherwise treat as a file item
        $fullPath = realpath($dirPath);
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

        $php    = PHP_BINARY;
        $script = realpath(dirname(__DIR__, 2) . '/bin/scan.php');
        exec(sprintf('%s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($script)));

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
            'scanning'        => (bool) ($state['running'] ?? false),
            'phase'           => $state['phase'] ?? null,
            'current_type'    => $state['current_type'] ?? null,
            'current_item_id' => $state['current_item_id'] ?? null,
            'current_group'   => $state['current_group'] ?? null,
            'total'           => (int) ($state['total'] ?? 0),
            'processed'       => (int) ($state['processed'] ?? 0),
            'type_totals'     => $state['type_totals'] ?? null,
            'finished_at'     => $state['finished_at'] ?? null,
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
        // Pull one indexed row to get metadata (prefer one that has been enriched)
        $entity = $this->db->first(
            'SELECT * FROM media WHERE type = ? AND (show_name = ? OR author = ?)
             ORDER BY metadata_fetched_at DESC NULLS LAST LIMIT 1',
            [$type, $urlPath, $urlPath]
        );

        if (!$entity) {
            $entries = $this->enrichEntriesWithMeta($this->dirEntries($dirPath, $urlPath), $type);
            return $this->browseDirectory($response, $type, $urlPath, $entries);
        }

        $meta          = json_decode($entity['metadata'] ?? '{}', true) ?? [];
        $seasonPosters = $meta['season_posters'] ?? [];
        $entries       = $this->dirEntries($dirPath, $urlPath);

        // Tag each season directory entry with its poster
        foreach ($entries as &$entry) {
            if ($entry['is_dir'] && preg_match('/(\d+)/', $entry['name'], $m)) {
                $entry['poster'] = $seasonPosters[(int) $m[1]] ?? null;
            }
        }
        unset($entry);

        $html = $this->twig->render('library/entity_detail.html.twig', [
            'type'    => $type,
            'name'    => $urlPath,
            'entity'  => $entity,
            'meta'    => $meta,
            'entries' => $entries,
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
        $prefix = $this->libraryPath . '/' . $type . '/';
        foreach ($items as &$item) {
            $item['relative_path'] = str_replace($prefix, '', $item['path']);
            $item['title']         = $this->cleanFilenameTitle($item['title'] ?? null) ?? $item['title'];
        }
        unset($item);
        $total = $this->db->first('SELECT COUNT(*) as n FROM media WHERE type = ?', [$type]);
        return [$items, (int) ($total['n'] ?? 0), false];
    }

    private function browseShows(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT show_name, COUNT(*) as episode_count, MAX(poster) as poster
             FROM media WHERE type = "shows" AND show_name IS NOT NULL
             GROUP BY show_name ORDER BY show_name LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT show_name) as n FROM media WHERE type = "shows"');
        return [$items, (int) ($total['n'] ?? 0), true];
    }

    private function browseMusic(int $offset, int $limit): array
    {
        $items = $this->db->query(
            'SELECT author, COUNT(*) as track_count, MAX(poster) as poster
             FROM media WHERE type = "music" AND author IS NOT NULL
             GROUP BY author ORDER BY author LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        $total = $this->db->first('SELECT COUNT(DISTINCT author) as n FROM media WHERE type = "music"');
        return [$items, (int) ($total['n'] ?? 0), true];
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

    private function enrichEntriesWithMeta(array $entries, string $type): array
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
            "SELECT id, path, title, poster, season, episode FROM media WHERE path IN ($placeholders)",
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
                    $entry['id']      = $m['id'];
                    $entry['title']   = $this->cleanFilenameTitle($m['title'] ?? null, $showName);
                    $entry['poster']  = $m['poster'] ?? null;
                    $entry['season']  = $m['season'] ?? null;
                    $entry['episode'] = $m['episode'] ?? null;
                }
            }
        }
        unset($entry);

        return $entries;
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
        return $entries;
    }
}
