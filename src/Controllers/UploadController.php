<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LibraryScanner;
use App\Services\Metadata\MetadataService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class UploadController
{
    private const VALID_CATEGORIES = ['movies', 'shows', 'music', 'books'];

    public function __construct(
        private readonly MetadataService $metadata,
        private readonly LibraryScanner $scanner,
        private readonly string $libraryPath
    ) {}

    public function upload(Request $request, Response $response): Response
    {
        // Prevent any PHP warnings (e.g. from mkdir/moveTo) from leaking into the JSON body.
        ob_start();

        try {
            $result = $this->doUpload($request);
        } finally {
            ob_end_clean();
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function doUpload(Request $request): array
    {
        $body     = $request->getParsedBody() ?? [];
        $category = $body['category'] ?? '';

        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return ['error' => 'Invalid category'];
        }

        $uploads = $request->getUploadedFiles()['files'] ?? [];
        if ($uploads instanceof UploadedFileInterface) {
            $uploads = [$uploads];
        }

        $results = [];
        foreach ($uploads as $upload) {
            if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
                $results[] = [
                    'filename' => $upload instanceof UploadedFileInterface ? $upload->getClientFilename() : 'unknown',
                    'success'  => false,
                    'error'    => 'Upload error code ' . ($upload instanceof UploadedFileInterface ? $upload->getError() : '?'),
                ];
                continue;
            }

            $results[] = $this->processFile($upload, $category);
        }

        // Trigger a background scan so the new files appear in the library.
        if (array_filter($results, fn($r) => $r['success'])) {
            $php    = PHP_BINARY;
            $script = realpath(dirname(__DIR__, 2) . '/bin/scan.php');
            if ($script) {
                exec(sprintf('%s %s %s > /dev/null 2>&1 &',
                    escapeshellarg($php), escapeshellarg($script), escapeshellarg($category)
                ));
            }
        }

        return $results;
    }

    // ── Per-file processing ───────────────────────────────────────────────────

    private function processFile(UploadedFileInterface $upload, string $category): array
    {
        $filename = $upload->getClientFilename() ?? 'upload';
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $hints = $this->parseFilename($category, $filename);
        $meta  = $this->searchMeta($category, $hints);

        $relPath = $this->resolveDestination($category, $ext, $hints, $meta);
        $absPath = $this->libraryPath . '/' . $category . '/' . $relPath;
        $absDir  = dirname($absPath);

        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            $mkdirErr = error_get_last()['message'] ?? 'Could not create directory';
            return ['filename' => $filename, 'success' => false, 'error' => $mkdirErr];
        }

        // Avoid overwriting — append a numeric suffix if needed.
        $absPath = $this->uniquePath($absPath, $ext);

        try {
            $upload->moveTo($absPath);
        } catch (\Throwable $e) {
            return ['filename' => $filename, 'success' => false, 'error' => $e->getMessage()];
        }

        return [
            'filename'    => $filename,
            'success'     => true,
            'destination' => $relPath,
            'matched'     => $meta['title'] ?? null,
        ];
    }

    // ── Metadata search ───────────────────────────────────────────────────────

    private function searchMeta(string $category, array $hints): ?array
    {
        $query  = $hints['query']  ?? null;
        $author = $hints['author'] ?? null;

        if (!$query) {
            return null;
        }

        // For shows, search by show title (strip episode info from query).
        $searchType = ($category === 'books' && ($author || ($hints['is_audiobook'] ?? false)))
            ? 'audiobooks'
            : $category;

        try {
            $results = $this->metadata->searchExternal($searchType, $query, $author);
        } catch (\Throwable) {
            return null;
        }

        return $results[0] ?? null;
    }

    // ── Filename parsing ──────────────────────────────────────────────────────

    private function parseFilename(string $category, string $filename): array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        return match ($category) {
            'movies' => $this->parseMovie($stem),
            'shows'  => $this->parseShow($stem),
            'books'  => $this->parseBook($stem, $filename),
            'music'  => $this->parseMusic($stem),
            default  => ['query' => $stem],
        };
    }

    private function parseMovie(string $stem): array
    {
        // Normalise: dots/underscores → spaces, strip quality tags and everything after them.
        $clean = preg_replace('/\b(1080p|720p|480p|4k|2160p|uhd|hdr10?\+?|dv|blu-?ray|bdrip|web-?dl|webrip|hdtv|dvdrip|x26[45]|hevc|aac|ac3|dts|h\.?26[45]|remux|proper|repack)\b.*/i', '', $stem);
        $clean = str_replace(['.', '_'], ' ', $clean);
        $clean = trim((string) preg_replace('/\s{2,}/', ' ', $clean));

        $year = null;
        if (preg_match('/^(.*?)\s*[\(\[]((?:19|20)\d{2})[\)\]]\s*$/', $clean, $m)) {
            $year  = (int) $m[2];
            $clean = trim($m[1]);
        } elseif (preg_match('/^(.*?)\s+((?:19|20)\d{2})\s*$/', $clean, $m)) {
            $year  = (int) $m[2];
            $clean = trim($m[1]);
        }

        return ['query' => $clean, 'title' => $clean, 'year' => $year];
    }

    private function parseShow(string $stem): array
    {
        $clean   = str_replace(['.', '_'], ' ', $stem);
        $season  = null;
        $episode = null;
        $show    = $clean;

        if (preg_match('/^(.*?)\s+[Ss](\d{1,2})[Ee](\d{1,2})/i', $clean, $m)) {
            $show    = trim($m[1]);
            $season  = (int) $m[2];
            $episode = (int) $m[3];
        } elseif (preg_match('/^(.*?)\s+(\d{1,2})x(\d{2})/i', $clean, $m)) {
            $show    = trim($m[1]);
            $season  = (int) $m[2];
            $episode = (int) $m[3];
        }

        return ['query' => $show, 'show' => $show, 'season' => $season, 'episode' => $episode];
    }

    private function parseBook(string $stem, string $filename): array
    {
        $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $clean = str_replace('_', ' ', $stem);

        $author      = null;
        $title       = $clean;
        $series      = null;
        $seriesOrder = null;

        // Double-dash format: "Title -- Author, Last -- extra metadata..."
        // Common in Anna's Archive and library catalog exports.
        if (str_contains($clean, ' -- ')) {
            $segs  = array_map('trim', explode(' -- ', $clean));
            $title = $segs[0];
            if (isset($segs[1])) {
                $rawAuthor = $segs[1];
                // Skip segments that look like metadata (edition, ISBN, year, hash)
                if (!preg_match('/^\d|^isbn|^First|^Second|^Third|^New |^[a-f0-9]{16,}/i', $rawAuthor)) {
                    // "Lastname, Firstname" → "Firstname Lastname"
                    if (preg_match('/^([A-Za-z\'\-]+),\s+(.+)$/', $rawAuthor, $am)) {
                        $author = trim($am[2]) . ' ' . trim($am[1]);
                    } else {
                        $author = $rawAuthor;
                    }
                }
            }
        }
        // "Author - Series #N - Title"
        elseif (preg_match('/^(.+?)\s+-\s+(.+?)\s+#(\d+(?:\.\d+)?)\s+-\s+(.+)$/u', $clean, $m)) {
            $author      = trim($m[1]);
            $series      = trim($m[2]);
            $seriesOrder = (float) $m[3];
            $title       = trim($m[4]);
        }
        // "Author - Title (Series #N)"
        elseif (preg_match('/^(.+?)\s+-\s+(.+?)\s+\((.+?)\s+#(\d+(?:\.\d+)?)\)$/u', $clean, $m)) {
            $author      = trim($m[1]);
            $title       = trim($m[2]);
            $series      = trim($m[3]);
            $seriesOrder = (float) $m[4];
        }
        // "Author - Title"
        elseif (preg_match('/^(.+?)\s+-\s+(.+)$/u', $clean, $m)) {
            $author = trim($m[1]);
            $title  = trim($m[2]);
        }

        return [
            'query'        => $title,
            'author'       => $author,
            'title'        => $title,
            'series'       => $series,
            'series_order' => $seriesOrder,
            'is_audiobook' => in_array($ext, ['m4b', 'mp3', 'flac', 'aac', 'ogg'], true),
        ];
    }

    private function parseMusic(string $stem): array
    {
        $clean  = str_replace('_', ' ', $stem);
        // Strip leading track number "01 - " or "01. "
        $clean  = preg_replace('/^\d+[\.\-\s]+/', '', $clean);
        $artist = null;
        $album  = null;

        // "Artist - Track" (single-file upload unlikely to carry album info, but handle common naming)
        if (preg_match('/^(.+?)\s+-\s+(.+)$/', $clean, $m)) {
            $artist = trim($m[1]);
            $album  = trim($m[2]);
        }

        return ['query' => $clean, 'artist' => $artist, 'album' => $album];
    }

    // ── Destination resolution ────────────────────────────────────────────────

    private function resolveDestination(string $category, string $ext, array $hints, ?array $meta): string
    {
        return match ($category) {
            'movies' => $this->moviePath($ext, $hints, $meta),
            'shows'  => $this->showPath($ext, $hints, $meta),
            'books'  => $this->bookPath($ext, $hints, $meta),
            'music'  => $this->musicPath($ext, $hints, $meta),
            default  => $this->safe($hints['query'] ?? 'upload') . '.' . $ext,
        };
    }

    private function moviePath(string $ext, array $hints, ?array $meta): string
    {
        $title = $meta['title'] ?? $hints['title'] ?? $hints['query'] ?? 'Unknown';
        $year  = $meta['year']  ?? $hints['year']  ?? null;
        $name  = $this->safe($title) . ($year ? ' (' . $year . ')' : '');
        return $name . '/' . $name . '.' . $ext;
    }

    private function showPath(string $ext, array $hints, ?array $meta): string
    {
        $show    = $meta['title']    ?? $hints['show']    ?? 'Unknown Show';
        $season  = $hints['season']  ?? 1;
        $episode = $hints['episode'] ?? 1;
        $showDir = $this->safe($show);
        $seDir   = 'Season ' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        $file    = $showDir . '.S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
                 . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT) . '.' . $ext;
        return $showDir . '/' . $seDir . '/' . $file;
    }

    private function bookPath(string $ext, array $hints, ?array $meta): string
    {
        $author = $hints['author'] ?? 'Unknown Author';
        $title  = $hints['title']  ?? $hints['query'] ?? 'Unknown';
        $series = $hints['series'] ?? null;

        // Use confirmed title and series from metadata when available.
        if ($meta && !empty($meta['title'])) {
            $title = $meta['title'];
        }
        if (!$series && $meta && !empty($meta['series'])) {
            $series = $meta['series'];
        }

        $authorDir = $this->safe($author);
        $titleDir  = $this->safe($title);
        $file      = $this->safe($title) . '.' . $ext;

        if ($series) {
            return $authorDir . '/' . $this->safe($series) . '/' . $titleDir . '/' . $file;
        }
        return $authorDir . '/' . $titleDir . '/' . $file;
    }

    private function musicPath(string $ext, array $hints, ?array $meta): string
    {
        $artist = $meta['author'] ?? $hints['artist'] ?? 'Unknown Artist';
        $album  = $meta['title']  ?? $hints['album']  ?? 'Unknown Album';
        return $this->safe($artist) . '/' . $this->safe($album) . '/' . $this->safe($hints['query'] ?? 'track') . '.' . $ext;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Strip filesystem-unsafe characters and trim. */
    private function safe(string $name): string
    {
        $name = preg_replace('/[\/\\\\\0\:\*\?\"\<\>\|]/', '', $name);
        $name = preg_replace('/\s{2,}/', ' ', $name);
        return trim($name, " .\t\n\r\0\x0B") ?: 'upload';
    }

    /** Return a path that doesn't collide with an existing file. */
    private function uniquePath(string $path, string $ext): string
    {
        if (!file_exists($path)) {
            return $path;
        }
        $base = substr($path, 0, -strlen('.' . $ext));
        $i    = 2;
        while (file_exists("$base ($i).$ext")) {
            $i++;
        }
        return "$base ($i).$ext";
    }
}
