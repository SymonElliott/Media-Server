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

    private int      $progressWriteCounter = 0;
    private ?array   $typeTotals           = null;
    private ?string  $currentScanName      = null;
    /** @var callable|null */
    private $logger = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $libraryPath,
        private readonly ?string $stateFile = null
    ) {}

    /** Attach a logger so Phase 1 writes progress lines to scan.log. */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    private function log(string $msg): void
    {
        if ($this->logger !== null) {
            ($this->logger)($msg);
        }
    }

    /** Inject pre-counted file totals (e.g. from DB row counts) so writeState() can report them. */
    public function setTypeTotals(array $totals): void
    {
        $this->typeTotals = $totals;
    }

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

    public function scan(?string $onlyType = null, ?string $onlyGroup = null): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        // Diagnostic: confirm scan() was entered and the library root is accessible.
        $this->log(sprintf(
            'Library root: %s  [%s]',
            $this->libraryPath,
            is_dir($this->libraryPath) ? 'ok' : 'NOT FOUND'
        ));

        foreach (($onlyType ? [$onlyType] : array_keys(self::EXTENSIONS)) as $type) {
            $typePath = $this->libraryPath . '/' . $type;
            if (!is_dir($typePath)) {
                $this->log("  {$type}/  → directory not found, skipping");
                continue;
            }

            $this->currentScanName = null;
            $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);

            // When a group is specified, only scan that subdirectory (e.g. one artist/author/show)
            $scanRoot = ($onlyGroup && $type !== 'movies')
                ? $typePath . '/' . $onlyGroup
                : $typePath;

            if (!is_dir($scanRoot)) {
                continue;
            }

            // CATCH_GET_CHILD silently skips subdirectories that cannot be opened
            // (permission denied, bad symlink, NFS stale handle, etc.) instead of
            // throwing and aborting the entire scan.
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($scanRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );
            } catch (\Throwable $e) {
                $this->log(sprintf(
                    '  ERROR: cannot open %s — %s (check directory permissions)',
                    $scanRoot,
                    $e->getMessage()
                ));
                continue;
            }

            $scannedPaths  = [];
            $typeAdded     = 0;
            $typeUpdated   = 0;
            $typeSkipped   = 0;

            try {
                foreach ($iterator as $file) {
                    try {
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

                        // Track the top-level directory (artist/author/show/movie-folder) and
                        // emit a state update + log line when we enter a new one.
                        $relPath = ltrim(substr($file->getPath(), strlen($scanRoot)), '/');
                        $topDir  = $relPath !== '' ? explode('/', $relPath)[0] : null;
                        if ($topDir !== null && $topDir !== $this->currentScanName) {
                            $this->currentScanName = $topDir;
                            $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);
                            $this->log('  ' . ucfirst($type) . ' › ' . $topDir);
                        }

                        $scannedPaths[] = $file->getRealPath();

                        try {
                            $isNew = $this->indexFile($file, $type);
                            if ($isNew) {
                                $stats['added']++;
                                $typeAdded++;
                            } else {
                                $stats['updated']++;
                                $typeUpdated++;
                            }
                        } catch (\Exception $e) {
                            $this->log('  SKIP ' . $file->getFilename() . ': ' . $e->getMessage());
                            $stats['skipped']++;
                            $typeSkipped++;
                        }

                        if (++$this->progressWriteCounter % 20 === 0) {
                            $this->writeState(['running' => true, 'current_type' => $type, ...$stats]);
                        }
                    } catch (\Throwable $e) {
                        // Per-file error (e.g. stat() on a broken symlink) — skip and continue.
                        $this->log('  SKIP (error): ' . $e->getMessage());
                        $stats['skipped']++;
                        $typeSkipped++;
                    }
                }
            } catch (\Throwable $e) {
                // Iterator-level error (directory became unavailable mid-scan).
                $this->log(sprintf('  ERROR traversing %s: %s', $type, $e->getMessage()));
            }

            // Log per-type summary
            $this->log(sprintf(
                '  %s done — %d added · %d updated · %d skipped',
                ucfirst($type), $typeAdded, $typeUpdated, $typeSkipped
            ));

            // Skip stale pruning for group scans — a full scan will clean up removed files
            if (!$onlyGroup) {
                $this->pruneStale($type, $scannedPaths);

                // After scanning books, also prune old audiobooks rows whose files
                // no longer exist (moved here from the former audiobooks directory).
                if ($type === 'books') {
                    $this->pruneType('audiobooks');
                }
            }
        }

        return $stats;
    }

    /**
     * Delete DB rows for any file of the given type whose path no longer exists on disk.
     */
    public function pruneType(string $type): int
    {
        $rows    = $this->db->query('SELECT id, path FROM v_media WHERE type = ?', [$type]);
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
             FROM v_media WHERE type IN ($placeholders)",
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
                "SELECT id FROM v_media WHERE filename = ? AND $groupCol = ? AND metadata_fetched_at IS NULL LIMIT 1",
                [$filename, $staleRow[$groupCol]]
            );
            if ($match) return $match;
        }

        if (!preg_match('/^(chapter|part|track|disc)\s*\d+/i', $filename)) {
            $match = $this->db->first(
                'SELECT id FROM v_media WHERE filename = ? AND metadata_fetched_at IS NULL LIMIT 1',
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
            if ($this->currentScanName !== null && !isset($data['current_scan_name'])) {
                $data['current_scan_name'] = $this->currentScanName;
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

        $series_order = match (true) {
            $dbType === 'audiobooks' || $dbType === 'books' =>
                $this->extractSeriesOrder($meta['book_name'] ?? $meta['title'] ?? '')
                ?? $this->extractSeriesOrder($file->getBasename('.' . $ext)),
            $dbType === 'music' =>
                $this->extractMusicTrackOrder($file),
            default => null,
        };

        // Check BEFORE upsert so we know if this is a new row.
        $existing = $this->db->first('SELECT id, episode FROM v_media WHERE path = ?', [$path]);

        $chapters = ($ext === 'm4b') ? $this->probeChapters($path) : null;

        // Step 1 — upsert the base media row (common fields only).
        $this->db->execute(<<<SQL
            INSERT INTO media (type, path, filename, extension, size, title, duration)
            VALUES (:type, :path, :filename, :extension, :size, :title, :duration)
            ON CONFLICT(path) DO UPDATE SET
                size       = excluded.size,
                title      = COALESCE(excluded.title, title),
                duration   = COALESCE(excluded.duration, duration),
                indexed_at = CURRENT_TIMESTAMP
        SQL, [
            'type'     => $dbType,
            'path'     => $path,
            'filename' => $file->getFilename(),
            'extension' => $ext,
            'size'     => $file->getSize(),
            'title'    => $meta['title'],
            'duration' => $duration,
        ]);

        // Fetch the media id (new insert or existing row).
        $mediaId = (int) ($this->db->first('SELECT id FROM media WHERE path = ?', [$path])['id'] ?? 0);

        // Step 2 — upsert the type-specific extension row.
        $this->upsertExtension($dbType, $mediaId, $meta, $series_order, $chapters);

        // For newly-inserted rows, recover any previously-enriched metadata that
        // was stored under a different path (e.g. after a file move or rename).
        if ($existing === null) {
            $this->inheritMetadata($dbType, $path, $meta);
        } elseif ($existing['episode'] === null && ($meta['episode'] ?? null) !== null) {
            // Episode number was just resolved (previously NULL, now known) — clear
            // metadata_fetched_at so enrichShowSeasons re-runs and sets the TMDB title.
            $this->db->execute(
                'UPDATE media SET metadata_fetched_at = NULL WHERE id = ?',
                [$existing['id']]
            );
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
                    'SELECT title, description, poster, external_id, external_source, year, series_order, metadata, metadata_fetched_at
                     FROM v_media
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
                     FROM v_media
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
                     FROM v_media
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

        // Update shared base fields.
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

        // Carry series_order to the books extension table.
        if (($donor['series_order'] ?? null) !== null && in_array($type, ['books', 'audiobooks'], true)) {
            $this->db->execute(
                'UPDATE media_books SET series_order = COALESCE(?, series_order)
                 WHERE media_id = (SELECT id FROM media WHERE path = ?)',
                [$donor['series_order'], $path]
            );
        }
    }

    /**
     * Derive a track-order float from a music filename.
     *
     * Handled patterns (extension already stripped):
     *   "01 - Song"     →  1        (single-disc, leading number)
     *   "1. Song"       →  1
     *   "Track 03"      →  3
     *   "2-05 Song"     →  205      (disc 2, track 5  → disc*100 + track)
     *   "1-12 Song"     →  112
     */
    private function extractMusicTrackOrder(SplFileInfo $file): ?float
    {
        $name = $file->getBasename('.' . strtolower($file->getExtension()));

        // Multi-disc: single digit disc + 1–3 digit track, e.g. "2-05", "1-12"
        if (preg_match('/^(\d)-(\d{1,3})\b/', $name, $m)) {
            return (float) ((int) $m[1] * 100 + (int) $m[2]);
        }

        // "Track 01" / "Track01"
        if (preg_match('/^track\s*(\d{1,3})/i', $name, $m)) {
            return (float) $m[1];
        }

        // "01 - Song", "01. Song", "01 Song", "1 - Song"
        if (preg_match('/^(\d{1,3})\s*[-._\s]/', $name, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * Upsert a row in the type-specific extension table.
     *
     * @param string      $type         DB type ('movies'|'shows'|'music'|'books'|'audiobooks')
     * @param int         $mediaId      media.id of the just-upserted base row
     * @param array       $meta         result of extractMeta() — still uses the old generic key names
     * @param float|null  $seriesOrder  pre-computed track/series order
     * @param string|null $chapters     JSON chapter list for M4B files
     */
    private function upsertExtension(
        string $type,
        int    $mediaId,
        array  $meta,
        ?float $seriesOrder,
        ?string $chapters
    ): void {
        match ($type) {
            'shows' => $this->db->execute(
                'INSERT INTO media_shows (media_id, show_name, season, episode)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(media_id) DO UPDATE SET
                     show_name = excluded.show_name,
                     season    = excluded.season,
                     episode   = excluded.episode',
                [$mediaId, $meta['show_name'], $meta['season'], $meta['episode']]
            ),

            'music' => $this->db->execute(
                'INSERT INTO media_music (media_id, artist, album, track_order)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(media_id) DO UPDATE SET
                     artist      = excluded.artist,
                     album       = excluded.album,
                     track_order = COALESCE(excluded.track_order, track_order)',
                // $meta['author'] = artist, $meta['series'] = album (from extractMeta path parts)
                [$mediaId, $meta['author'], $meta['series'], $seriesOrder]
            ),

            'books', 'audiobooks' => $this->db->execute(
                'INSERT INTO media_books (media_id, book_name, author, series, series_order, book_version, chapters)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(media_id) DO UPDATE SET
                     book_name    = excluded.book_name,
                     author       = excluded.author,
                     series       = COALESCE(excluded.series, series),
                     series_order = COALESCE(excluded.series_order, series_order),
                     book_version = excluded.book_version,
                     chapters     = COALESCE(excluded.chapters, chapters)',
                [$mediaId, $meta['book_name'], $meta['author'], $meta['series'],
                 $seriesOrder, $meta['book_version'], $chapters !== null ? json_encode($chapters) : null]
            ),

            default => null, // movies: no extension table needed
        };
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
        $ffprobe = $this->findFfprobe();
        if (!$ffprobe) {
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

    private function probeChapters(string $path): ?array
    {
        $ffprobe = $this->findFfprobe();
        if (!$ffprobe) {
            return null;
        }
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_chapters %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($path)
        );
        $out  = (string) shell_exec($cmd);
        $data = json_decode($out, true);
        if (!isset($data['chapters']) || !is_array($data['chapters'])) {
            return null;
        }
        $chapters = [];
        foreach ($data['chapters'] as $ch) {
            $start = (float) ($ch['start_time'] ?? 0);
            $title = $ch['tags']['title'] ?? ('Chapter ' . (count($chapters) + 1));
            $chapters[] = ['title' => $title, 'start' => $start];
        }
        return count($chapters) > 1 ? $chapters : null;
    }

    /** Locate the ffprobe binary, checking common install locations before PATH. */
    private function findFfprobe(): ?string
    {
        static $cache;
        if ($cache !== null) {
            return $cache === '' ? null : $cache;
        }

        foreach ([
            '/opt/homebrew/bin/ffprobe',   // macOS (Homebrew Apple Silicon / Intel)
            '/usr/local/bin/ffprobe',      // macOS (Homebrew alt), Docker custom installs
            '/usr/bin/ffprobe',            // Linux (apt/dnf package)
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $cache = $candidate;
            }
        }

        // Last resort: search $PATH
        $which = trim((string) shell_exec('which ffprobe 2>/dev/null'));
        $cache = ($which !== '' && is_executable($which)) ? $which : '';
        return $cache !== '' ? $cache : null;
    }

    private function extractMeta(SplFileInfo $file, string $type): array
    {
        $relative = ltrim(str_replace($this->libraryPath . '/' . $type, '', $file->getPath()), '/');
        $parts    = array_values(array_filter(explode('/', $relative)));
        $depth    = count($parts);

        $season  = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : null;
        $episode = null;
        if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})/', $file->getFilename(), $m)) {
            // Standard SxxExx format
            $season  = (int) $m[1];
            $episode = (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})\.(\d{2})\b/', $file->getFilename(), $m)) {
            // NN.NN - Title format (e.g. "01.05 - The Enchiridion.mkv")
            $fileSeason = (int) $m[1];
            $episode    = (int) $m[2];
            if ($season === null || $fileSeason === $season) {
                $season = $fileSeason;
            }
            // If filename season differs from directory season (e.g. a Specials folder),
            // keep the directory-derived season and use the second number as the episode.
        } elseif (preg_match('/\b(\d{1,2})x(\d{2,3})\b/i', $file->getFilename(), $m)) {
            // NxNN format (e.g. "1x05 - Title.mkv")
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

            // Books structure: Author / [Series /] Book / files-or-narrator-dirs
            //
            // depth 2 → Author / Book / file              (direct ebook or single audio)
            // depth 3 → Author / Book / NarratorDir / chapter   (MP3 chapters, no series)
            //        OR  Author / Series / Book / file           (series direct file)
            // depth 4 → Author / Series / Book / NarratorDir / chapter
            //
            // Disambiguation at depth 3: chapter audio formats (mp3/flac/aac/ogg/wav)
            // are always in a narrator dir under Book; everything else is Series/Book/file.
            //
            // Legacy depth-1 M4B (Author/BookTitle.m4b) is still accepted for compat.
            'books' => (function () use ($file, $parts, $depth): array {
                $author = $parts[0] ?? null;
                $ext    = strtolower($file->getExtension());
                $title  = $file->getBasename('.' . $ext);

                $chapterExts = ['mp3', 'flac', 'aac', 'ogg', 'wav'];

                // Legacy: M4B loose in Author dir
                if ($depth === 1 && $ext === 'm4b') {
                    return [
                        'title'        => $title,
                        'author'       => $author,
                        'series'       => null,
                        'book_name'    => $title,
                        'book_version' => null,
                        'show_name'    => null,
                        'season'       => null,
                        'episode'      => null,
                    ];
                }

                // depth 2: Author / Book / file  (direct ebook or M4B)
                if ($depth === 2) {
                    return [
                        'title'        => $title,
                        'author'       => $author,
                        'series'       => null,
                        'book_name'    => $parts[1] ?? null,
                        'book_version' => null,
                        'show_name'    => null,
                        'season'       => null,
                        'episode'      => null,
                    ];
                }

                // depth 3: Author/Book/Narrator/chapter or (legacy) Author/OldSeries/Book/file
                if ($depth === 3) {
                    if (in_array($ext, $chapterExts, true)) {
                        // Author / Book / Narrator / chapter.mp3
                        return [
                            'title'        => $title,
                            'author'       => $author,
                            'series'       => null,
                            'book_name'    => $parts[1] ?? null,
                            'book_version' => $parts[2] ?? null,
                            'show_name'    => null,
                            'season'       => null,
                            'episode'      => null,
                        ];
                    }
                    // Legacy: Author / OldSeriesDir / Book / file — book_name is parts[2].
                    // series is never set from path; metadata enrichment sets it instead.
                    return [
                        'title'        => $title,
                        'author'       => $author,
                        'series'       => null,
                        'book_name'    => $parts[2] ?? null,
                        'book_version' => null,
                        'show_name'    => null,
                        'season'       => null,
                        'episode'      => null,
                    ];
                }

                // depth 4: Author / OldSeriesDir / Book / Narrator / chapter.mp3
                return [
                    'title'        => $title,
                    'author'       => $author,
                    'series'       => null,
                    'book_name'    => $parts[2] ?? null,
                    'book_version' => $parts[3] ?? null,
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

            // Movies: prefer the folder name over the filename — folder names are
            // typically clean ("The Batman (2022)") while filenames carry noise
            // ("The.Batman.2022.1080p.BluRay.x265-GROUP").
            // Fall back to the filename when the file sits directly in movies/.
            default => [
                'title'        => $depth >= 1 ? $parts[0] : $file->getBasename('.' . $file->getExtension()),
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
