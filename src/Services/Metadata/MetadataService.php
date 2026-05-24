<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Database\Connection;
use App\Services\RenameService;
use GuzzleHttp\ClientInterface;

class MetadataService
{
    private mixed $logger = null; // callable|null — `callable` is not a valid property type in PHP

    public function __construct(
        private readonly Connection    $db,
        private readonly TmdbProvider  $tmdb,
        private readonly MusicBrainzProvider $musicBrainz,
        private readonly OpenLibraryProvider $openLibrary,
        private readonly AudnexusProvider    $audnexus,
        private readonly ClientInterface     $http,
        private readonly string              $coversDir,
        private readonly ?RenameService      $renamer = null
    ) {}

    /** Inject a logging callback — called with a single string message. */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }

    // ── Public API ────────────────────────────────────────────────────────

    /** Return up to 6 search results from the appropriate external provider. */
    public function searchExternal(string $type, string $query, ?string $author = null): array
    {
        return match ($type) {
            'movies'              => $this->tmdb->searchMovieMulti($query),
            'shows'               => $this->tmdb->searchShowMulti($query),
            'music'               => $this->musicBrainz->searchReleaseMulti($query),
            'audiobooks'          => $this->audnexusOrOpenLibraryMulti($query, $author),
            'books'               => $this->openLibrary->searchMulti($query, $author),
            default               => [],
        };
    }

    /** Search using a specific provider by name, bypassing type-based routing. */
    public function searchByProvider(string $provider, string $query, ?string $author = null): array
    {
        return match ($provider) {
            'tmdb'         => $this->tmdb->searchMovieMulti($query),
            'tmdb_show'    => $this->tmdb->searchShowMulti($query),
            'musicbrainz'  => $this->musicBrainz->searchReleaseMulti($query),
            'audnexus'     => $this->audnexus->searchMulti($query, $author),
            'openlibrary'  => $this->openLibrary->searchMulti($query, $author),
            default        => [],
        };
    }

    /** Re-enrich a single item using a specific externally-matched ID. */
    public function applyExternalMatch(int $mediaId, string $source, string $externalId): void
    {
        $item = $this->db->first('SELECT * FROM v_media WHERE id = ?', [$mediaId]);
        if (!$item) return;

        $meta = match ($source) {
            'tmdb' => match ($item['type']) {
                'movies' => $this->tmdb->fetchMovieById((int) $externalId),
                'shows'  => $this->tmdb->fetchShowById((int) $externalId),
                default  => null,
            },
            'audnexus'    => $this->audnexus->fetchByAsin($externalId),
            'openlibrary' => $this->openLibrary->fetchByKey($externalId),
            'musicbrainz' => $this->musicBrainz->fetchRelease($externalId),
            default       => null,
        };

        if (!$meta) return;

        if ($item['type'] === 'shows' && $item['show_name']) {
            $this->applyToShow($item['show_name'], $meta, true);
            if ($meta['external_id'] ?? null) {
                // Manual match: download episode stills too
                $this->enrichShowSeasons($item['show_name'], (int) $meta['external_id'], true);
            }
            $this->renamer?->renameShowEpisodes($item['show_name']);
        } elseif ($item['type'] === 'audiobooks' && $item['book_name']) {
            $this->applyToBook($item['book_name'], $meta, true);
            $this->renamer?->renameBookFiles($item['book_name']);
        } elseif ($item['type'] === 'music') {
            $this->applyToAlbum($item['author'], $item['series'], $meta, true);
            $this->renamer?->renameAlbumTracks($item['author'] ?? '', $item['series'] ?? '');
        } else {
            $this->applyToSingle($mediaId, $meta, true);
            $this->renamer?->renameItem($mediaId);
        }
    }

    /** Enrich all items that haven't been fetched yet. Called from bin/scan.php. */
    public function enrichAll(?callable $onProgress = null): void
    {
        $this->enrichMovies($onProgress);
        $this->enrichShows($onProgress);
        $this->enrichMusic($onProgress);
        $this->enrichBooks($onProgress);
        $this->enrichAudiobooks($onProgress);
    }

    /** Enrich all items of a specific type that haven't been fetched yet. */
    public function enrichType(string $type, ?callable $onProgress = null, ?string $filterGroup = null): void
    {
        match ($type) {
            'movies'     => $this->enrichMovies($onProgress),
            'shows'      => $this->enrichShows($onProgress, $filterGroup),
            'music'      => $this->enrichMusic($onProgress, $filterGroup),
            'books'      => $this->enrichBooksOfType('books', $onProgress, $filterGroup),
            'audiobooks' => $this->enrichAudiobooks($onProgress, $filterGroup),
            default      => null,
        };
    }

    /** Refresh series metadata (clears then re-enriches from OpenLibrary). */
    public function refreshSeriesMeta(string $series, string $author): void
    {
        $this->db->execute(
            'INSERT OR IGNORE INTO series_meta (series, author) VALUES (?, ?)',
            [$series, $author]
        );
        $this->db->execute(
            'UPDATE series_meta SET metadata_fetched_at = NULL WHERE series = ? AND author = ?',
            [$series, $author]
        );
        $this->enrichSeries($series, $author);
    }

    /** Enrich a single item by ID. Used by the manual refresh button. */
    public function enrichOne(int $id): void
    {
        $item = $this->db->first('SELECT * FROM v_media WHERE id = ?', [$id]);
        if (!$item) return;

        // Clear fetched timestamp so enrichment runs even if previously attempted
        $this->db->execute('UPDATE media SET metadata_fetched_at = NULL WHERE id = ?', [$id]);
        $item['metadata_fetched_at'] = null;

        match ($item['type']) {
            'movies'    => $this->enrichMovie($item),
            'shows'     => $this->enrichShow($item['show_name'], true), // manual: include stills
            'music'     => $this->enrichAlbum($item['author'], $item['series']),
            'audiobooks' => $this->enrichAudiobook(
                $item['book_name'],
                $item['author'],
                $this->cleanAudiobookTitle($item['book_name'], $item['path'], $item['author'])
            ),
            'books', 'cookbooks' => $this->enrichBook($item),
            default     => null,
        };
    }

    /** For the Edit-modal search: ASIN query goes to Audnexus, text query goes to OpenLibrary. */
    private function audnexusOrOpenLibraryMulti(string $query, ?string $author): array
    {
        $audnexusResults = $this->audnexus->searchMulti($query, $author);
        return $audnexusResults ?: $this->openLibrary->searchMulti($query, $author);
    }

    // ── Per-type batch enrichment ─────────────────────────────────────────

    private function enrichMovies(?callable $onProgress): void
    {
        $items = $this->db->query(
            'SELECT * FROM v_media WHERE type = "movies" AND metadata_fetched_at IS NULL'
        );
        foreach ($items as $item) {
            if ($onProgress) $onProgress('movies', $item['id'], $item['title'] ?? $item['filename'] ?? '');
            try {
                $this->enrichMovie($item);
            } catch (\Throwable $e) {
                $this->log('[movies] ERROR: ' . $e->getMessage());
            }
            usleep(150_000); // stay well under TMDB rate limit
        }
    }

    private function enrichShows(?callable $onProgress, ?string $filterGroup = null): void
    {
        $groupFilter = $filterGroup ? ' AND show_name = ?' : '';
        $groupParams = $filterGroup ? [$filterGroup] : [];

        // Pass 1: shows with no metadata at all
        $newShows = $this->db->query(
            'SELECT DISTINCT show_name FROM v_media
             WHERE type = "shows" AND show_name IS NOT NULL
               AND show_name NOT IN (
                   SELECT DISTINCT show_name FROM v_media
                   WHERE type = "shows" AND metadata_fetched_at IS NOT NULL
               )' . $groupFilter,
            $groupParams
        );
        foreach ($newShows as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name'], $row['show_name']);
            try {
                // No episode stills during automated scan — fetched manually via Edit modal
                $this->enrichShow($row['show_name'], false);
            } catch (\Throwable $e) {
                $this->log('[shows] ERROR: ' . $e->getMessage());
            }
            usleep(150_000);
        }

        // Pass 2: shows that have a TMDB ID but still need per-episode data —
        // either season thumbnails haven't been fetched yet, or new episodes were
        // added since the last enrichment (metadata_fetched_at IS NULL on some rows).
        $needsSeasons = $this->db->query(
            'SELECT DISTINCT show_name, MAX(external_id) as tmdb_id FROM v_media
             WHERE type = "shows" AND external_source = "tmdb" AND external_id IS NOT NULL
               AND (
                   metadata IS NULL
                   OR metadata NOT LIKE "%season_posters%"
                   OR show_name IN (
                       SELECT DISTINCT show_name FROM v_media
                       WHERE type = "shows" AND metadata_fetched_at IS NULL
                   )
               )' . $groupFilter . '
             GROUP BY show_name',
            $groupParams
        );
        foreach ($needsSeasons as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name'], $row['show_name']);
            try {
                // Still no episode stills — this pass is also part of the automated scan
                $this->enrichShowSeasons($row['show_name'], (int) $row['tmdb_id'], false);
                // Stamp any newly-added episodes so they don't trigger this pass on the next scan
                $this->db->execute(
                    'UPDATE media SET metadata_fetched_at = CURRENT_TIMESTAMP
                     WHERE id IN (SELECT media_id FROM media_shows WHERE show_name = ?)
                       AND metadata_fetched_at IS NULL',
                    [$row['show_name']]
                );
            } catch (\Throwable $e) {
                $this->log('[shows] ERROR: ' . $e->getMessage());
            }
        }
    }

    private function enrichMusic(?callable $onProgress, ?string $filterGroup = null): void
    {
        $groupFilter = $filterGroup ? ' AND author = ?' : '';
        $groupParams = $filterGroup ? [$filterGroup] : [];

        $albums = $this->db->query(
            'SELECT DISTINCT author, series FROM v_media
             WHERE type = "music" AND author IS NOT NULL AND metadata_fetched_at IS NULL' . $groupFilter,
            $groupParams
        );
        foreach ($albums as $album) {
            if ($onProgress) $onProgress('music', $album['author'], $album['author']);
            try {
                $this->enrichAlbum($album['author'], $album['series']);
            } catch (\Throwable $e) {
                $this->log('[music] ERROR: ' . $e->getMessage());
            }
            sleep(1); // MusicBrainz: 1 req/second
        }
    }

    private function enrichBooks(?callable $onProgress): void
    {
        $this->enrichBooksOfType('books', $onProgress);
    }

    private function enrichBooksOfType(?string $type, ?callable $onProgress, ?string $filterGroup = null): void
    {
        $groupFilter = $filterGroup ? ' AND author = ?' : '';
        $groupParams = $filterGroup ? [$filterGroup] : [];

        $items = $type
            ? $this->db->query(
                'SELECT * FROM v_media WHERE type = ? AND metadata_fetched_at IS NULL' . $groupFilter,
                array_merge([$type], $groupParams)
              )
            : $this->db->query(
                'SELECT * FROM v_media WHERE type = "books" AND metadata_fetched_at IS NULL' . $groupFilter,
                $groupParams
              );
        foreach ($items as $item) {
            if ($onProgress) $onProgress($item['type'], $item['id'], ($item['book_name'] ?? null) ?: ($item['title'] ?? $item['filename'] ?? ''));
            try {
                $this->enrichBook($item);
            } catch (\Throwable $e) {
                $this->log('[books] ERROR: ' . $e->getMessage());
            }
            usleep(250_000);
        }
    }

    private function enrichAudiobooks(?callable $onProgress, ?string $filterGroup = null): void
    {
        $groupFilter = $filterGroup ? ' AND author = ?' : '';
        $groupParams = $filterGroup ? [$filterGroup] : [];

        // Group by book so we do one lookup per book, not one per chapter file.
        // Include a sample path so we can extract the clean directory-based title.
        $books = $this->db->query(
            'SELECT book_name, author, series, MIN(path) as sample_path FROM v_media
             WHERE type = "audiobooks" AND book_name IS NOT NULL AND metadata_fetched_at IS NULL' . $groupFilter . '
             GROUP BY book_name, author',
            $groupParams
        );
        foreach ($books as $book) {
            $cleanTitle = $this->cleanAudiobookTitle($book['book_name'], $book['sample_path'], $book['author']);
            // Use series name as group key so the series row on the browse page gets
            // highlighted; fall back to raw book_name for standalone books.
            $groupKey = $book['series'] ?? $book['book_name'];
            if ($onProgress) $onProgress('audiobooks', $groupKey, $cleanTitle);
            try {
                $this->enrichAudiobook($book['book_name'], $book['author'], $cleanTitle);
            } catch (\Throwable $e) {
                $this->log('[audiobooks] ERROR: ' . $e->getMessage());
            }
            usleep(250_000);
        }

        // After all books are enriched, enrich any series that have no series_meta yet.
        $seriesList = $this->db->query(
            'SELECT DISTINCT series, author FROM v_media
             WHERE type = "audiobooks" AND series IS NOT NULL AND book_name IS NOT NULL' . $groupFilter,
            $groupParams
        );
        foreach ($seriesList as $row) {
            $existing = $this->db->first(
                'SELECT metadata_fetched_at FROM series_meta WHERE series = ? AND (author = ? OR author IS NULL)',
                [$row['series'], $row['author']]
            );
            if ($existing && $existing['metadata_fetched_at']) continue;
            if ($onProgress) $onProgress('audiobooks', $row['series'], $row['series']);
            try {
                $this->enrichSeries($row['series'], $row['author']);
            } catch (\Throwable $e) {
                $this->log('[audiobooks] ERROR enriching series "' . $row['series'] . '": ' . $e->getMessage());
            }
            usleep(250_000);
        }
    }

    // ── Per-item enrichment ───────────────────────────────────────────────

    private function enrichMovie(array $item): void
    {
        $rawTitle    = $item['title'] ?? $item['filename'];
        $searchTitle = $this->cleanMovieSearchTitle($rawTitle);
        $this->log("[movies] \"{$searchTitle}\" → TMDB...");
        $meta = $this->tmdb->searchMovie($searchTitle, $item['year']);
        if ($meta) {
            $this->log("[movies] \"{$searchTitle}\" ✓ {$meta['title']}" . ($meta['year'] ? " ({$meta['year']})" : ''));
        } else {
            $this->log("[movies] \"{$searchTitle}\" ✗ no match");
        }
        $this->applyToSingle($item['id'], $meta, true);
        $this->renamer?->renameItem($item['id']);
    }

    /**
     * Reduce a raw movie title / filename to a clean TMDB search string.
     *
     * Strategy: replace separators → strip brackets → cut at the first
     * release year (19xx / 20xx preceded by a space so titles like "1917"
     * or "2001: A Space Odyssey" are never accidentally truncated).
     * If no year is found, fall back to stripping known quality/noise tags.
     */
    private function cleanMovieSearchTitle(string $title): string
    {
        // Replace common filename separators with spaces
        $clean = str_replace(['.', '_'], ' ', $title);

        // Strip content in brackets / parens (quality tags, year in parens, etc.)
        $clean = preg_replace('/\s*[\[\(][^\]\)]{0,40}[\]\)]\s*/', ' ', $clean);

        // Primary: truncate at the first 4-digit release year preceded by whitespace.
        // This handles "Movie Title 2023 FRENCH 1080p BluRay" → "Movie Title" in one step.
        if (preg_match('/^(.*?)\s+(?:19|20)\d{2}\b/', $clean, $m) && trim($m[1]) !== '') {
            return trim($m[1]);
        }

        // Fallback: strip known quality / encoding / language tags and everything after.
        $clean = preg_replace(
            '/\s+\b(?:480p|576p|720p|1080p|2160p|4[Kk]|UHD|Blu-?Ray|BDRip|BRRip|WEB-?DL|WEBRip|HDTV|DVDRip|HDRip|x264|x265|H\.?264|H\.?265|HEVC|AVC|AAC|AC3|DTS|HDR|SDR|NF|AMZN|DSNP|REPACK|PROPER|EXTENDED|UNRATED|THEATRICAL|REMUX|FRENCH|MULTI|MULTi|VOSTFR|TRUEFRENCH)\b.*$/i',
            '',
            $clean
        );
        return trim($clean);
    }

    /**
     * Clean a show folder name for TMDB search: replace dots/underscores
     * with spaces and strip a trailing release year.
     *
     * "The.Flash.2014" → "The Flash"
     * "Breaking Bad (2008)" → "Breaking Bad"
     */
    private function cleanShowSearchTitle(string $name): string
    {
        $clean = str_replace(['.', '_'], ' ', $name);
        // Strip trailing parenthetical year
        $clean = preg_replace('/\s*\(\s*(?:19|20)\d{2}\s*\)\s*$/', '', $clean);
        // Strip trailing bare year
        $clean = preg_replace('/\s+(?:19|20)\d{2}\s*$/', '', $clean);
        return trim($clean);
    }

    private function enrichShow(string $showName, bool $downloadStills = false): void
    {
        $searchName = $this->cleanShowSearchTitle($showName);
        $this->log("[shows] \"{$searchName}\" → TMDB...");
        $meta = $this->tmdb->searchShow($searchName);
        if ($meta) {
            $this->log("[shows] \"{$showName}\" ✓ {$meta['title']}" . ($meta['year'] ? " ({$meta['year']})" : ''));
        } else {
            $this->log("[shows] \"{$showName}\" ✗ no match");
        }
        $this->applyToShow($showName, $meta, true);

        if (($meta['external_id'] ?? null) && ($meta['external_source'] ?? null) === 'tmdb') {
            $seasonCount = (int) ($this->db->first(
                'SELECT COUNT(DISTINCT season) as n FROM v_media WHERE type = "shows" AND show_name = ?',
                [$showName]
            )['n'] ?? 0);
            $this->log("[shows] \"{$showName}\" fetching {$seasonCount} season(s)...");
            $this->enrichShowSeasons($showName, (int) $meta['external_id'], $downloadStills);
        }

        $this->renamer?->renameShowEpisodes($showName);
    }

    /**
     * Fetch per-season data from TMDB: season posters, episode titles, and
     * optionally episode stills.
     *
     * Episode stills are skipped during automated background scans ($downloadStills = false)
     * because a large library can have thousands of them — each is a separate HTTP
     * download that adds minutes to the scan.  They are fetched on-demand when the
     * user explicitly refreshes a show's metadata via the Edit modal.
     */
    private function enrichShowSeasons(string $showName, int $tmdbId, bool $downloadStills = false): void
    {
        $seasons = $this->db->query(
            'SELECT DISTINCT season FROM v_media
             WHERE type = "shows" AND show_name = ? AND season IS NOT NULL
             ORDER BY season',
            [$showName]
        );

        $seasonPosters = [];

        foreach ($seasons as $row) {
            $n          = (int) $row['season'];
            $seasonData = $this->tmdb->fetchSeasonDetails($tmdbId, $n);

            if ($seasonData) {
                // Season poster (one per season — kept even in fast mode)
                if ($seasonData['poster_url']) {
                    $poster = $this->downloadCover($seasonData['poster_url'], "season_{$tmdbId}_{$n}");
                    if ($poster) $seasonPosters[$n] = $poster;
                }

                // Episode titles + stills — match by show_name + season + episode number
                foreach ($seasonData['episodes'] as $ep) {
                    // Stills are skipped during automated scans to avoid hundreds of downloads
                    $still = null;
                    if ($downloadStills && $ep['still_url']) {
                        $still = $this->downloadCover($ep['still_url'], "ep_{$tmdbId}_{$n}_{$ep['number']}");
                    }
                    // Episode title lives on media base; episode still lives in media_shows.
                    $this->db->execute(
                        'UPDATE media SET title = COALESCE(:title, title)
                         WHERE id IN (
                             SELECT media_id FROM media_shows
                             WHERE show_name = :show AND season = :season AND episode = :episode
                         )',
                        [
                            'title'   => $ep['name'] ?? null,
                            'show'    => $showName,
                            'season'  => $n,
                            'episode' => $ep['number'],
                        ]
                    );
                    if ($still !== null) {
                        $this->db->execute(
                            'UPDATE media_shows SET still = COALESCE(:still, still)
                             WHERE show_name = :show AND season = :season AND episode = :episode',
                            ['still' => $still, 'show' => $showName, 'season' => $n, 'episode' => $ep['number']]
                        );
                    }
                }
            }

            usleep(200_000); // ~5 season requests/second, well under TMDB limit
        }

        // Persist season poster map into every episode's metadata JSON
        if ($seasonPosters) {
            $this->db->execute(
                'UPDATE media
                 SET metadata = json_set(COALESCE(metadata, "{}"), "$.season_posters", json(?))
                 WHERE type = "shows" AND show_name = ?',
                [json_encode($seasonPosters), $showName]
            );
        }
    }

    private function enrichAlbum(?string $artist, ?string $album): void
    {
        if (!$artist) return;
        $label = $album ? "\"{$artist} / {$album}\"" : "\"{$artist}\"";
        $this->log("[music] {$label} → MusicBrainz...");
        $meta = $this->musicBrainz->searchRelease($artist, $album);
        if ($meta) {
            $this->log("[music] {$label} ✓ {$meta['title']}" . ($meta['year'] ? " ({$meta['year']})" : ''));
        } else {
            $this->log("[music] {$label} ✗ no match");
        }
        $this->applyToAlbum($artist, $album, $meta, true);

        // Apply per-track ordering using the MusicBrainz track listing.
        // release_mbid is stored in the metadata so we can look up the exact
        // track list (release-group IDs don't carry track positions).
        $releaseMbid = $meta['metadata']['release_mbid'] ?? null;
        if ($releaseMbid && $artist && $album) {
            usleep(1_100_000); // MB rate limit: 1 req/sec
            $this->applyTrackOrder($artist, $album, $releaseMbid);
        }

        $this->renamer?->renameAlbumTracks($artist, $album ?? '');
    }

    /**
     * Fetch the track listing for a release and write series_order to each matched row.
     *
     * Matching is done by normalised title so "01 - Stairway to Heaven" in the DB
     * matches "Stairway to Heaven" from MusicBrainz.  Unmatched tracks keep whatever
     * series_order was extracted from the filename during scanning.
     */
    private function applyTrackOrder(string $artist, string $album, string $releaseMbid): void
    {
        $trackMap = $this->musicBrainz->fetchReleaseTracks($releaseMbid);
        if (!$trackMap) {
            return;
        }

        $rows = $this->db->query(
            'SELECT id, title, filename FROM v_media WHERE type = "music" AND author = ? AND series = ?',
            [$artist, $album]
        );

        $matched = 0;
        foreach ($rows as $row) {
            // Use DB title (which may already be the canonical name from a previous
            // enrichment run) or fall back to the filename stem.
            $title      = $row['title'] ?? pathinfo((string) $row['filename'], PATHINFO_FILENAME);
            $normalised = $this->musicBrainz->normaliseTitle($title);

            if (isset($trackMap[$normalised])) {
                $this->db->execute(
                    'UPDATE media_music SET track_order = ? WHERE media_id = ?',
                    [$trackMap[$normalised], $row['id']]
                );
                $matched++;
            }
        }

        if ($matched > 0) {
            $this->log("[music] track order set for {$matched} / " . count($rows) . " tracks in \"{$album}\"");
        }
    }

    private function enrichBook(array $item): void
    {
        // Prefer the directory-derived book_name (e.g. "The Way of Kings") over the
        // filename-derived title (often just "book" or a generic placeholder).
        $raw   = ($item['book_name'] ?? null) ?: ($item['title'] ?? $item['filename']);
        $title = $this->cleanBookSearchTitle($raw, $item['author'] ?? null);
        $this->log("[books] \"{$title}\" → OpenLibrary...");
        $meta = $this->openLibrary->search($title, $item['author']);
        if ($meta) {
            $this->log("[books] \"{$title}\" ✓ {$meta['title']}" . ($meta['year'] ? " ({$meta['year']})" : ''));
        } else {
            $this->log("[books] \"{$title}\" ✗ no match");
        }
        $this->applyToSingle($item['id'], $meta, true);
        $this->renamer?->renameItem($item['id']);
    }

    private function enrichAudiobook(?string $bookName, ?string $author, ?string $cleanTitle = null): void
    {
        if (!$bookName) return;
        $title = $cleanTitle ?? $bookName;
        // Prefer Audnexus when the title/filename contains an ASIN; otherwise use OpenLibrary.
        $hasAsin = (bool) preg_match('/\bB[0-9A-Z]{9}\b/', $title);
        $provider = $hasAsin ? 'Audnexus' : 'OpenLibrary';
        $this->log("[audiobooks] \"{$title}\" → {$provider}...");
        $meta = $this->audnexus->search($title, $author)
            ?? $this->openLibrary->search($title, $author);
        if ($meta) {
            $src = $meta['external_source'] ?? $provider;
            $this->log("[audiobooks] \"{$title}\" ✓ {$meta['title']}" . ($meta['year'] ? " ({$meta['year']}) via {$src}" : " via {$src}"));
        } else {
            $this->log("[audiobooks] \"{$title}\" ✗ no match");
        }
        $this->applyToBook($bookName, $meta, true);
        $this->renamer?->renameBookFiles($bookName);
    }

    // ── DB update helpers ─────────────────────────────────────────────────

    private function applyToSingle(int $id, ?array $meta, bool $overwrite = false): void
    {
        $poster = ($meta['poster_url'] ?? null) ? $this->downloadCover($meta['poster_url'], (string) $id, $overwrite) : null;
        $w = fn(string $col, string $param) => $overwrite ? $param : "COALESCE($param, $col)";

        $this->db->execute(
            'UPDATE media SET
                title               = ' . $w('title', ':title') . ',
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                year                = ' . $w('year', ':year') . ',
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'title'           => $meta['title'] ?? null,
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'year'            => $meta['year'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'id'              => $id,
            ]
        );

        // series_order belongs in media_books (books/audiobooks only).
        if (($meta['series_order'] ?? null) !== null) {
            $this->db->execute(
                'UPDATE media_books SET series_order = COALESCE(?, series_order) WHERE media_id = ?',
                [$meta['series_order'], $id]
            );
        }
    }

    private function applyToShow(string $showName, ?array $meta, bool $overwrite = false): void
    {
        $poster = ($meta['poster_url'] ?? null)
            ? $this->downloadCover($meta['poster_url'], 'show_' . md5($showName), $overwrite)
            : null;
        $w = fn(string $col, string $param) => $overwrite ? $param : "COALESCE($param, $col)";

        $this->db->execute(
            'UPDATE media SET
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                year                = ' . $w('year', ':year') . ',
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE id IN (SELECT media_id FROM media_shows WHERE show_name = :show_name)',
            [
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'year'            => $meta['year'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'show_name'       => $showName,
            ]
        );
    }

    private function applyToBook(string $bookName, ?array $meta, bool $overwrite = false): void
    {
        $poster = ($meta['poster_url'] ?? null) ? $this->downloadCover($meta['poster_url'], 'book_' . md5($bookName), $overwrite) : null;
        $w = fn(string $col, string $param) => $overwrite ? $param : "COALESCE($param, $col)";

        // Shared metadata applies to every file in the book (description, poster, etc.)
        // book_name is in media_books, so route the WHERE through the extension table.
        $this->db->execute(
            'UPDATE media SET
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                year                = ' . $w('year', ':year') . ',
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE id IN (SELECT media_id FROM media_books WHERE book_name = :book_name)',
            [
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'year'            => $meta['year'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'book_name'       => $bookName,
            ]
        );

        // series_order lives in media_books.
        if ($meta['series_order'] ?? null) {
            $this->db->execute(
                'UPDATE media_books SET series_order = COALESCE(:series_order, series_order)
                 WHERE book_name = :book_name',
                ['series_order' => $meta['series_order'], 'book_name' => $bookName]
            );
        }

        // Title only makes sense at the whole-book level, not for individual chapters.
        // Only overwrite it when there is exactly one file for this book_name (single-file
        // audiobook like an M4B), so chapter file titles (track names / "Chapter N") are
        // preserved.
        $fileCount = (int) ($this->db->first(
            'SELECT COUNT(*) as n FROM v_media WHERE type = "audiobooks" AND book_name = ?',
            [$bookName]
        )['n'] ?? 0);

        if ($overwrite || $fileCount <= 1) {
            $this->db->execute(
                'UPDATE media SET title = ' . $w('title', ':title') . '
                 WHERE id IN (SELECT media_id FROM media_books WHERE book_name = :book_name)',
                ['title' => $meta['title'] ?? null, 'book_name' => $bookName]
            );
        }
    }

    private function applyToAlbum(?string $artist, ?string $album, ?array $meta, bool $overwrite = false): void
    {
        $key    = 'album_' . md5(($artist ?? '') . ':' . ($album ?? ''));
        $poster = ($meta['poster_url'] ?? null) ? $this->downloadCover($meta['poster_url'], $key, $overwrite) : null;
        $w = fn(string $col, string $param) => $overwrite ? $param : "COALESCE($param, $col)";

        $this->db->execute(
            'UPDATE media SET
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                year                = ' . $w('year', ':year') . ',
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE id IN (
                 SELECT media_id FROM media_music
                 WHERE artist = :artist AND (album = :album OR (:album IS NULL AND album IS NULL))
             )',
            [
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'year'            => $meta['year'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'artist'          => $artist,
                'album'           => $album,
            ]
        );

        // Mirror into album_meta for the album detail page
        if ($artist && $album) {
            $this->db->execute(
                'INSERT OR IGNORE INTO album_meta (album, artist) VALUES (?, ?)',
                [$album, $artist]
            );
            $this->db->execute(
                'UPDATE album_meta SET
                    title               = ' . $w('title', ':title') . ',
                    description         = ' . $w('description', ':description') . ',
                    poster              = COALESCE(:poster, poster),
                    year                = ' . $w('year', ':year') . ',
                    external_id         = ' . $w('external_id', ':external_id') . ',
                    external_source     = ' . $w('external_source', ':external_source') . ',
                    metadata            = :metadata,
                    metadata_fetched_at = CURRENT_TIMESTAMP
                 WHERE album = :album AND artist = :artist',
                [
                    'title'           => $meta['title'] ?? $album,
                    'description'     => $meta['description'] ?? null,
                    'poster'          => $poster,
                    'year'            => $meta['year'] ?? null,
                    'external_id'     => $meta['external_id'] ?? null,
                    'external_source' => $meta['external_source'] ?? null,
                    'metadata'        => json_encode($meta['metadata'] ?? []),
                    'album'           => $album,
                    'artist'          => $artist,
                ]
            );
        }
    }

    public function refreshAlbumMeta(string $album, string $artist): void
    {
        $this->db->execute(
            'INSERT OR IGNORE INTO album_meta (album, artist) VALUES (?, ?)',
            [$album, $artist]
        );
        $this->db->execute(
            'UPDATE album_meta SET metadata_fetched_at = NULL WHERE album = ? AND artist = ?',
            [$album, $artist]
        );
        $this->enrichAlbum($artist, $album);
    }

    private function enrichSeries(string $series, string $author): void
    {
        // Use OpenLibrary for series-level metadata (title, description, year, poster)
        $meta = $this->openLibrary->search($series, $author);

        // Look for an author ASIN from any Audnexus-enriched book in this series,
        // then fetch the author photo from Audnexus.
        $authorAsin  = null;
        $authorImage = null;
        $bookRow     = $this->db->first(
            "SELECT metadata FROM v_media WHERE type = 'audiobooks' AND series = ? AND external_source = 'audnexus' LIMIT 1",
            [$series]
        );
        if ($bookRow) {
            $bm         = json_decode($bookRow['metadata'] ?? '{}', true);
            $authorAsin = $bm['author_asin'] ?? null;
        }
        if ($authorAsin) {
            $authorData = $this->audnexus->fetchAuthor($authorAsin);
            if ($authorData && ($authorData['image_url'] ?? null)) {
                $authorImage = $this->downloadCover($authorData['image_url'], 'author_' . $authorAsin);
            }
        }

        $this->applyToSeries($series, $author, $meta, true, $authorAsin, $authorImage);
    }

    private function applyToSeries(
        string  $series,
        string  $author,
        ?array  $meta,
        bool    $overwrite = false,
        ?string $authorAsin = null,
        ?string $authorImage = null
    ): void {
        $poster = ($meta['poster_url'] ?? null)
            ? $this->downloadCover($meta['poster_url'], 'series_' . md5($series . ':' . $author), $overwrite)
            : null;
        $w = fn(string $col, string $param) => $overwrite ? $param : "COALESCE($param, $col)";

        $this->db->execute(
            'INSERT OR IGNORE INTO series_meta (series, author) VALUES (?, ?)',
            [$series, $author]
        );
        $this->db->execute(
            'UPDATE series_meta SET
                title               = ' . $w('title', ':title') . ',
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                author_image        = COALESCE(:author_image, author_image),
                author_asin         = COALESCE(:author_asin, author_asin),
                year                = ' . $w('year', ':year') . ',
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE series = :series AND author = :author',
            [
                'title'           => $meta['title'] ?? $series,
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'author_image'    => $authorImage,
                'author_asin'     => $authorAsin,
                'year'            => $meta['year'] ?? null,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'series'          => $series,
                'author'          => $author,
            ]
        );
    }

    // ── Title extraction helpers ──────────────────────────────────────────

    /**
     * For audiobooks the clean title is the parent directory name, not the raw
     * book_name (which is often an M4B filename with Audible IDs and series suffixes).
     *
     * Structure: Author/[Series/]BookDir/Book.m4b  → BookDir is the clean title.
     * For chapter-file books: Author/[Series/]BookDir/ch01.mp3 → BookDir is book_name already.
     */
    private function cleanAudiobookTitle(string $bookName, ?string $path, ?string $author = null): string
    {
        if ($path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            // M4B files are self-contained books placed directly in Author/[Series]/ —
            // there is no intermediate "book directory", so parentDir is the Author or
            // Series folder, not a meaningful book title. Fall through to filename-based cleaning.
            if ($ext !== 'm4b') {
                $parentDir = basename(dirname($path));
                // Parent dir is the book directory when it's neither a root segment nor the book_name itself.
                if ($parentDir && $parentDir !== $bookName && !in_array(strtolower($parentDir), ['audiobooks', '.', '..'], true)) {
                    return $parentDir;
                }
            }
        }

        // Fallback: strip common Audible/ISBN suffixes and appended series info from the filename.
        $clean = preg_replace('/\s*[\[{]B[A-Z0-9]{9,10}[\]}]/i', '', $bookName); // [BASIN] or {BASIN}
        $clean = preg_replace('/\s*[\[{]\d{9,13}[\]}]/', '', $clean);             // [ISBN] or {ISBN}
        $clean = preg_replace('/[_:]\s*.{0,60}(,\s*Book\s*[\d.]+)?$/i', '', $clean); // _ Series info, Book N

        if ($author) {
            $escapedAuthor = preg_quote($author, '/');
            // Strip leading "Author Name - Title" or "Author Name: Title" prefix
            $clean = preg_replace('/^' . $escapedAuthor . '\s*[-–:]\s*/iu', '', $clean);
            // Strip trailing "Title - Author Name" or "Title (Author Name)" suffix
            $clean = preg_replace('/\s*[-–]\s*' . $escapedAuthor . '\s*$/iu', '', $clean);
            $clean = preg_replace('/\s*\(' . $escapedAuthor . '\)\s*$/iu', '', $clean);
        }

        $clean = trim($clean);
        return $clean !== '' ? $clean : $bookName;
    }

    private function cleanBookSearchTitle(string $title, ?string $author = null): string
    {
        // Replace underscores/dots used as word separators
        $clean = str_replace(['_', '.'], ' ', $title);

        if ($author) {
            $ea = preg_quote($author, '/');
            $clean = preg_replace('/^' . $ea . '\s*[-–:]\s*/iu', '', $clean);
            $clean = preg_replace('/\s*[-–]\s*' . $ea . '\s*$/iu', '', $clean);
            $clean = preg_replace('/\s*\(' . $ea . '\)\s*$/iu', '', $clean);
        } else {
            // Fallback heuristic when author isn't known
            $clean = preg_replace('/\s+[-–]\s+[A-Z][a-zA-Z ]+$/', '', $clean);
        }

        // Strip edition markers like "(2nd ed)" or "[Revised]"
        $clean = preg_replace('/\s*[\(\[](revised|updated|edition|\d+(st|nd|rd|th)\s*ed)[^\)\]]*[\)\]]/i', '', $clean);
        return trim(preg_replace('/\s{2,}/', ' ', $clean));
    }

    // ── Image download ────────────────────────────────────────────────────

    private function downloadCover(string $url, string $key, bool $force = false): ?string
    {
        if (!is_dir($this->coversDir)) {
            mkdir($this->coversDir, 0755, true);
        }

        $filename = substr(md5($key), 0, 16) . '.jpg';
        $diskPath = $this->coversDir . '/' . $filename;
        $webPath  = '/covers/' . $filename;

        if (file_exists($diskPath)) {
            if (!$force) return $webPath;
            @unlink($diskPath);
        }

        try {
            $res = $this->http->get($url, ['sink' => $diskPath, 'timeout' => 15]);
            // Reject non-200 responses (e.g. CAA 404 JSON error bodies that have size > 0)
            if ($res->getStatusCode() !== 200) {
                @unlink($diskPath);
                return null;
            }
            return file_exists($diskPath) && filesize($diskPath) > 0 ? $webPath : null;
        } catch (\Throwable) {
            @unlink($diskPath);
            return null;
        }
    }
}
