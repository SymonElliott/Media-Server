<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LibraryScanner
{
    private const EXTENSIONS = [
        'movies' => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'shows'  => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'music'  => ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav'],
        // books dir holds both ebooks (→ type=books) and audiobooks (→ type=audiobooks).
        // The actual DB type is determined per-file by extension in indexFile().
        'books'  => ['pdf', 'epub', 'mobi', 'azw', 'azw3', 'cbr', 'cbz',
                     'mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'],
    ];

    private const AUDIO_EXTS = ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'];

    private int    $progressWriteCounter = 0;
    private ?array $typeTotals           = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $libraryPath,
        private readonly ?string $stateFile = null
    ) {}

    /** Count files per type (or just one type). Stored internally so writeState() can include them. */
    public function countFiles(?string $onlyType = null): array
    {
        $totals = [];
        foreach (($onlyType ? [$onlyType] : array_keys(self::EXTENSIONS)) as $type) {
            $count    = 0;
            $typePath = $this->libraryPath . '/' . $type;
            if (is_dir($typePath)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($typePath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS[$type], true)) {
                        $count++;
                    }
                }
            }
            $totals[$type] = $count;
        }
        $this->typeTotals = $totals;
        return $totals;
    }

    public function scan(?string $onlyType = null): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (($onlyType ? [$onlyType] : array_keys(self::EXTENSIONS)) as $type) {
            $typePath = $this->libraryPath . '/' . $type;
            if (!is_dir($typePath)) {
                continue;
            }

            $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($typePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $scannedPaths = [];

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if ($file->getFilename() === '.DS_Store') {
                    @unlink($file->getRealPath());
                    continue;
                }

                $ext = strtolower($file->getExtension());
                if (!in_array($ext, self::EXTENSIONS[$type], true)) {
                    continue;
                }

                $scannedPaths[] = $file->getRealPath();

                try {
                    $isNew = $this->indexFile($file, $type);
                    $isNew ? $stats['added']++ : $stats['updated']++;
                } catch (\Exception) {
                    $stats['skipped']++;
                }

                if (++$this->progressWriteCounter % 20 === 0) {
                    $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);
                }
            }

            $this->pruneStale($type, $scannedPaths);

            // After scanning books, also prune old audiobooks rows whose files
            // no longer exist (moved here from the former audiobooks directory).
            if ($type === 'books') {
                $this->pruneType('audiobooks');
            }
        }

        return $stats;
    }

    /**
     * Delete DB rows for any file of the given type whose path no longer exists on disk.
     */
    public function pruneType(string $type): int
    {
        $rows    = $this->db->query('SELECT id, path FROM media WHERE type = ?', [$type]);
        $removed = 0;
        foreach ($rows as $row) {
            if (!file_exists($row['path'])) {
                $this->db->execute('DELETE FROM media WHERE id = ?', [$row['id']]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Remove DB rows for files that were deleted from disk during a scan.
     * Migrates enriched metadata to a newly-indexed row at the new location when possible.
     */
    private function pruneStale(string $type, array $scannedPaths): void
    {
        $onDisk = array_flip($scannedPaths);

        // For the books directory we must check both the 'books' and 'audiobooks'
        // DB type since audio files in /books/ are stored as type=audiobooks.
        $dbTypes = ($type === 'books') ? ['books', 'audiobooks'] : [$type];
        $placeholders = implode(',', array_fill(0, count($dbTypes), '?'));

        $dbRows = $this->db->query(
            "SELECT id, path, filename, book_name, book_version, show_name, series,
                    title, description, poster, external_id, external_source,
                    year, metadata, metadata_fetched_at
             FROM media WHERE type IN ($placeholders)",
            $dbTypes
        );

        foreach ($dbRows as $row) {
            if (isset($onDisk[$row['path']])) {
                continue;
            }

            if ($row['metadata_fetched_at']) {
                $newRow = $this->findRelocated($row, $type);
                if ($newRow) {
                    $this->db->execute(
                        'UPDATE media SET
                            title               = COALESCE(:title, title),
                            description         = COALESCE(:description, description),
                            poster              = COALESCE(:poster, poster),
                            external_id         = COALESCE(:external_id, external_id),
                            external_source     = COALESCE(:external_source, external_source),
                            year                = COALESCE(:year, year),
                            metadata            = COALESCE(:metadata, metadata),
                            metadata_fetched_at = COALESCE(:metadata_fetched_at, metadata_fetched_at)
                         WHERE id = :id',
                        [
                            'title'               => $row['title'],
                            'description'         => $row['description'],
                            'poster'              => $row['poster'],
                            'external_id'         => $row['external_id'],
                            'external_source'     => $row['external_source'],
                            'year'                => $row['year'],
                            'metadata'            => $row['metadata'],
                            'metadata_fetched_at' => $row['metadata_fetched_at'],
                            'id'                  => $newRow['id'],
                        ]
                    );
                }
            }

            $this->db->execute('DELETE FROM media WHERE id = ?', [$row['id']]);
        }
    }

    private function findRelocated(array $staleRow, string $type): ?array
    {
        $filename = $staleRow['filename'];

        $groupCol = match ($type) {
            'books'      => 'book_name',
            'audiobooks' => 'book_name',
            'shows'      => 'show_name',
            default      => null,
        };

        if ($groupCol && $staleRow[$groupCol]) {
            $match = $this->db->first(
                "SELECT id FROM media WHERE filename = ? AND $groupCol = ? AND metadata_fetched_at IS NULL LIMIT 1",
                [$filename, $staleRow[$groupCol]]
            );
            if ($match) return $match;
        }

        if (!preg_match('/^(chapter|part|track|disc)\s*\d+/i', $filename)) {
            $match = $this->db->first(
                'SELECT id FROM media WHERE filename = ? AND metadata_fetched_at IS NULL LIMIT 1',
                [$filename]
            );
            return $match ?: null;
        }

        return null;
    }

    private function writeState(array $data): void
    {
        if ($this->stateFile !== null) {
            if (!isset($data['processed'])) {
                $data['processed'] = ($data['added'] ?? 0) + ($data['updated'] ?? 0) + ($data['skipped'] ?? 0);
            }
            if ($this->typeTotals !== null && !isset($data['type_totals'])) {
                $data['type_totals'] = $this->typeTotals;
            }
            file_put_contents($this->stateFile, json_encode($data), LOCK_EX);
        }
    }

    private function indexFile(SplFileInfo $file, string $type): bool
    {
        $path = $file->getRealPath();
        $ext  = strtolower($file->getExtension());
        $meta = $this->extractMeta($file, $type);

        // Audio files in the books directory are stored as type=audiobooks so
        // MetadataService routes them to the correct enrichment provider.
        $dbType = ($type === 'books' && in_array($ext, self::AUDIO_EXTS, true))
            ? 'audiobooks'
            : $type;

        $needsDuration = ($dbType === 'audiobooks') || ($type === 'books' && in_array($ext, self::AUDIO_EXTS, true));
        $duration      = $needsDuration ? $this->probeDuration($path) : null;

        $series_order = ($dbType === 'audiobooks' || $dbType === 'books')
            ? $this->extractSeriesOrder($meta['book_name'] ?? $meta['title'] ?? $file->getBasename('.' . $ext))
            : null;

        $existing = $this->db->first('SELECT id FROM media WHERE path = ?', [$path]);

        $this->db->execute(<<<SQL
            INSERT INTO media (type, path, filename, extension, size, title, author, series,
                               book_name, book_version, show_name, season, episode, duration, series_order)
            VALUES (:type, :path, :filename, :extension, :size, :title, :author, :series,
                    :book_name, :book_version, :show_name, :season, :episode, :duration, :series_order)
            ON CONFLICT(path) DO UPDATE SET
                size         = excluded.size,
                series       = excluded.series,
                book_name    = excluded.book_name,
                book_version = excluded.book_version,
                season       = excluded.season,
                episode      = excluded.episode,
                duration     = excluded.duration,
                series_order = excluded.series_order,
                indexed_at   = CURRENT_TIMESTAMP
        SQL, [
            'type'         => $dbType,
            'path'         => $path,
            'filename'     => $file->getFilename(),
            'extension'    => $ext,
            'size'         => $file->getSize(),
            'duration'     => $duration,
            'series_order' => $series_order,
            ...$meta,
        ]);

        // For newly-inserted rows, recover any previously-enriched metadata that
        // was stored under a different path (e.g. after a file move or rename).
        if ($existing === null) {
            $this->inheritMetadata($dbType, $path, $meta);
        }

        return $existing === null;
    }

    private function inheritMetadata(string $type, string $path, array $meta): void
    {
        $donor = null;

        if ($type === 'books' || $type === 'audiobooks') {
            $author   = $meta['author']    ?? null;
            $bookName = $meta['book_name'] ?? null;
            if ($author && $bookName) {
                $donor = $this->db->first(
                    'SELECT title, description, poster, external_id, external_source, year, metadata, metadata_fetched_at
                     FROM media
                     WHERE type IN ("books","audiobooks")
                       AND author = ? AND book_name = ?
                       AND metadata_fetched_at IS NOT NULL
                       AND path != ?
                     ORDER BY metadata_fetched_at DESC LIMIT 1',
                    [$author, $bookName, $path]
                );
            }
        } elseif ($type === 'shows') {
            $showName = $meta['show_name'] ?? null;
            $season   = $meta['season']   ?? null;
            $episode  = $meta['episode']  ?? null;
            if ($showName && $season !== null && $episode !== null) {
                $donor = $this->db->first(
                    'SELECT title, description, poster, external_id, external_source, year, metadata, metadata_fetched_at
                     FROM media
                     WHERE type = "shows"
                       AND show_name = ? AND season = ? AND episode = ?
                       AND metadata_fetched_at IS NOT NULL
                       AND path != ?
                     ORDER BY metadata_fetched_at DESC LIMIT 1',
                    [$showName, $season, $episode, $path]
                );
            }
        } elseif ($type === 'movies') {
            $title = $meta['title'] ?? null;
            if ($title) {
                $donor = $this->db->first(
                    'SELECT title, description, poster, external_id, external_source, year, metadata, metadata_fetched_at
                     FROM media
                     WHERE type = "movies"
                       AND title = ?
                       AND metadata_fetched_at IS NOT NULL
                       AND path != ?
                     ORDER BY metadata_fetched_at DESC LIMIT 1',
                    [$title, $path]
                );
            }
        }

        if (!$donor) {
            return;
        }

        $this->db->execute(
            'UPDATE media SET
                title               = COALESCE(:title, title),
                description         = COALESCE(:description, description),
                poster              = COALESCE(:poster, poster),
                external_id         = COALESCE(:external_id, external_id),
                external_source     = COALESCE(:external_source, external_source),
                year                = COALESCE(:year, year),
                metadata            = COALESCE(:metadata, metadata),
                metadata_fetched_at = COALESCE(:metadata_fetched_at, metadata_fetched_at)
             WHERE path = :path',
            [
                'title'               => $donor['title'],
                'description'         => $donor['description'],
                'poster'              => $donor['poster'],
                'external_id'         => $donor['external_id'],
                'external_source'     => $donor['external_source'],
                'year'                => $donor['year'],
                'metadata'            => $donor['metadata'],
                'metadata_fetched_at' => $donor['metadata_fetched_at'],
                'path'                => $path,
            ]
        );
    }

    private function extractSeriesOrder(string $name): ?float
    {
        if (preg_match('/\bBook\s+#?([\d]+(?:\.[\d]+)?)\b/i', $name, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/\s#([\d]+(?:\.[\d]+)?)\b/', $name, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^([\d]+(?:\.[\d]+)?)[.\s_-]/', $name, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    private function probeDuration(string $path): ?int
    {
        $ffprobe = '/opt/homebrew/bin/ffprobe';
        if (!is_executable($ffprobe)) {
            return null;
        }
        $cmd = sprintf(
            '%s -v quiet -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($path)
        );
        $out = trim((string) shell_exec($cmd));
        return $out !== '' && is_numeric($out) ? (int) round((float) $out) : null;
    }

    private function extractMeta(SplFileInfo $file, string $type): array
    {
        $relative = ltrim(str_replace($this->libraryPath . '/' . $type, '', $file->getPath()), '/');
        $parts    = array_values(array_filter(explode('/', $relative)));
        $depth    = count($parts);

        $season  = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : null;
        $episode = null;
        if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})/', $file->getFilename(), $m)) {
            $season  = (int) $m[1];
            $episode = (int) $m[2];
        }

        return match ($type) {
            'shows' => [
                'title'        => $file->getBasename('.' . $file->getExtension()),
                'author'       => null,
                'series'       => null,
                'book_name'    => null,
                'book_version' => null,
                'show_name'    => $parts[0] ?? null,
                'season'       => $season,
                'episode'      => $episode,
            ],

            // New unified books structure: Author/[Series/]BookTitle/Version/files
            // depth 3 → Author / Book / Version  (no series)
            // depth 4 → Author / Series / Book / Version
            'books' => (function () use ($file, $parts, $depth): array {
                $author      = $parts[0] ?? null;
                $bookVersion = $depth >= 1 ? ($parts[$depth - 1] ?? null) : null;
                $bookName    = $depth >= 2 ? ($parts[$depth - 2] ?? null) : null;
                $series      = $depth >= 4 ? $parts[1] : null;

                return [
                    'title'        => $file->getBasename('.' . $file->getExtension()),
                    'author'       => $author,
                    'series'       => $series,
                    'book_name'    => $bookName,
                    'book_version' => $bookVersion,
                    'show_name'    => null,
                    'season'       => null,
                    'episode'      => null,
                ];
            })(),

            'music' => [
                'title'        => $file->getBasename('.' . $file->getExtension()),
                'author'       => $parts[0] ?? null,
                'series'       => $parts[1] ?? null,
                'book_name'    => null,
                'book_version' => null,
                'show_name'    => null,
                'season'       => null,
                'episode'      => null,
            ],

            default => [
                'title'        => $file->getBasename('.' . $file->getExtension()),
                'author'       => null,
                'series'       => null,
                'book_name'    => null,
                'book_version' => null,
                'show_name'    => null,
                'season'       => null,
                'episode'      => null,
            ],
        };
    }
}
