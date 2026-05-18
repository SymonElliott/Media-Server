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
        'movies'     => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'shows'      => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'music'      => ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav'],
        'audiobooks' => ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'],
        'books'      => ['pdf', 'epub', 'mobi', 'azw', 'azw3', 'cbr', 'cbz'],
    ];

    private int    $progressWriteCounter = 0;
    private ?array $typeTotals           = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $libraryPath,
        private readonly ?string $stateFile = null
    ) {}

    /** Count files per type (or just one type). Stored internally so writeState() can include them automatically. */
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

                // Clean up macOS metadata noise automatically
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

                // Write progress every 20 files to avoid hammering disk
                if (++$this->progressWriteCounter % 20 === 0) {
                    $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);
                }
            }

            $this->pruneStale($type, $scannedPaths);
        }

        return $stats;
    }

    /**
     * Quick cleanup: delete DB rows for any file of the given type whose path no
     * longer exists on disk. Does not scan for new files — call this between full
     * scans to keep the library tidy after manual deletes or folder moves.
     * Returns the number of rows removed.
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
     * Remove DB rows for files that no longer exist at their stored path.
     * Before deleting, migrate any enriched metadata to a newly-added row that
     * represents the same file at its new location (same filename + grouping key).
     */
    private function pruneStale(string $type, array $scannedPaths): void
    {
        // Build a lookup set of all paths found on disk this scan
        $onDisk = array_flip($scannedPaths);

        // Load all DB rows for this type (only columns needed for matching + migration)
        $dbRows = $this->db->query(
            'SELECT id, path, filename, book_name, show_name, series,
                    title, description, poster, external_id, external_source,
                    year, metadata, metadata_fetched_at
             FROM media WHERE type = ?',
            [$type]
        );

        foreach ($dbRows as $row) {
            if (isset($onDisk[$row['path']])) {
                continue; // still on disk, nothing to do
            }

            // File is gone from disk. Try to find the new path for this file
            // by matching the newly-inserted blank row with the same filename + group key.
            if ($row['metadata_fetched_at']) {
                $newRow = $this->findRelocated($row, $type);
                if ($newRow) {
                    // Migrate enriched metadata from the old row to the new path's row
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

    /**
     * Given a stale row, find the newly-inserted row that represents the same file
     * at its new location. Matches on filename + the group key appropriate for each type.
     */
    private function findRelocated(array $staleRow, string $type): ?array
    {
        $filename = $staleRow['filename'];

        // Group key used to identify "same file, different folder"
        $groupCol = match ($type) {
            'audiobooks' => 'book_name',
            'shows'      => 'show_name',
            default      => null,
        };

        // For audiobooks/shows we can match on filename + group — but if the group
        // itself was renamed, fall back to filename-only within the type (only safe
        // when the filename is unique enough, e.g. episode files or M4Bs).
        if ($groupCol && $staleRow[$groupCol]) {
            $match = $this->db->first(
                "SELECT id FROM media WHERE type = ? AND filename = ? AND $groupCol = ? AND metadata_fetched_at IS NULL LIMIT 1",
                [$type, $filename, $staleRow[$groupCol]]
            );
            if ($match) return $match;
        }

        // Fallback: same filename + type + no metadata yet (just inserted this scan)
        // Only use this when filename is specific enough (not generic like "chapter01.mp3")
        if (!preg_match('/^(chapter|part|track|disc)\s*\d+/i', $filename)) {
            $match = $this->db->first(
                'SELECT id FROM media WHERE type = ? AND filename = ? AND metadata_fetched_at IS NULL LIMIT 1',
                [$type, $filename]
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
        $path         = $file->getRealPath();
        $meta         = $this->extractMeta($file, $type);
        $duration     = $type === 'audiobooks' ? $this->probeDuration($path) : null;
        $series_order = ($type === 'audiobooks' || $type === 'books')
            ? $this->extractSeriesOrder($meta['book_name'] ?? $meta['title'] ?? $file->getBasename('.' . $file->getExtension()))
            : null;

        $existing = $this->db->first('SELECT id FROM media WHERE path = ?', [$path]);

        $this->db->execute(<<<SQL
            INSERT INTO media (type, path, filename, extension, size, title, author, series, book_name, show_name, season, episode, duration, series_order)
            VALUES (:type, :path, :filename, :extension, :size, :title, :author, :series, :book_name, :show_name, :season, :episode, :duration, :series_order)
            ON CONFLICT(path) DO UPDATE SET
                size         = excluded.size,
                series       = excluded.series,
                book_name    = excluded.book_name,
                season       = excluded.season,
                episode      = excluded.episode,
                duration     = excluded.duration,
                series_order = excluded.series_order,
                indexed_at   = CURRENT_TIMESTAMP
        SQL, [
            'type'         => $type,
            'path'         => $path,
            'filename'     => $file->getFilename(),
            'extension'    => strtolower($file->getExtension()),
            'size'         => $file->getSize(),
            'duration'     => $duration,
            'series_order' => $series_order,
            ...$meta,
        ]);

        return $existing === null;
    }

    private function extractSeriesOrder(string $name): ?float
    {
        // "Book 7" / "Book 7.5" / "Book #7"
        if (preg_match('/\bBook\s+#?([\d]+(?:\.[\d]+)?)\b/i', $name, $m)) {
            return (float) $m[1];
        }
        // "#12" after whitespace (series number marker)
        if (preg_match('/\s#([\d]+(?:\.[\d]+)?)\b/', $name, $m)) {
            return (float) $m[1];
        }
        // Leading number: "01 - Title" or "1. Title"
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

        // Parse SxxExx from filename — more reliable than directory name for season,
        // and the only source for episode number.
        $season  = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : null;
        $episode = null;
        if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})/', $file->getFilename(), $m)) {
            $season  = (int) $m[1];
            $episode = (int) $m[2];
        }

        return match ($type) {
            'shows' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => null,
                'series'    => null,
                'book_name' => null,
                'show_name' => $parts[0] ?? null,
                'season'    => $season,
                'episode'   => $episode,
            ],
            'audiobooks' => (function () use ($file, $parts): array {
                $basename = $file->getBasename('.' . $file->getExtension());
                $ext      = strtolower($file->getExtension());
                if ($ext === 'm4b') {
                    // M4B is always a self-contained book; the filename is the book title
                    return [
                        'title'     => $basename,
                        'author'    => $parts[0] ?? null,
                        'series'    => $parts[1] ?? null, // enclosing dir = series (if any)
                        'book_name' => $basename,
                        'show_name' => null,
                        'season'    => null,
                        'episode'   => null,
                    ];
                }
                // MP3/FLAC/etc: enclosing directory is the book, files are chapters
                return [
                    'title'     => $basename,
                    'author'    => $parts[0] ?? null,
                    'series'    => isset($parts[2]) ? $parts[1] : null,
                    'book_name' => $parts[2] ?? $parts[1] ?? $basename,
                    'show_name' => null,
                    'season'    => null,
                    'episode'   => null,
                ];
            })(),
            'books' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => $parts[0] ?? null,
                'series'    => $parts[1] ?? null,
                'book_name' => null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
            'music' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => $parts[0] ?? null,
                'series'    => $parts[1] ?? null,
                'book_name' => null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
            default => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => null,
                'series'    => null,
                'book_name' => null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
        };
    }
}
