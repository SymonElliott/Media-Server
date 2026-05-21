<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Database\Connection;
use App\Services\RenameService;
use GuzzleHttp\ClientInterface;

class MetadataService
{
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
        $item = $this->db->first('SELECT * FROM media WHERE id = ?', [$mediaId]);
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
                $this->enrichShowSeasons($item['show_name'], (int) $meta['external_id']);
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
        $item = $this->db->first('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$item) return;

        // Clear fetched timestamp so enrichment runs even if previously attempted
        $this->db->execute('UPDATE media SET metadata_fetched_at = NULL WHERE id = ?', [$id]);
        $item['metadata_fetched_at'] = null;

        match ($item['type']) {
            'movies'    => $this->enrichMovie($item),
            'shows'     => $this->enrichShow($item['show_name']),
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
            'SELECT * FROM media WHERE type = "movies" AND metadata_fetched_at IS NULL'
        );
        foreach ($items as $item) {
            if ($onProgress) $onProgress('movies', $item['id'], $item['title'] ?? $item['filename'] ?? '');
            try {
                $this->enrichMovie($item);
            } catch (\Throwable) {
                // Don't let one bad item abort the whole scan
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
            'SELECT DISTINCT show_name FROM media
             WHERE type = "shows" AND show_name IS NOT NULL
               AND show_name NOT IN (
                   SELECT DISTINCT show_name FROM media
                   WHERE type = "shows" AND metadata_fetched_at IS NOT NULL
               )' . $groupFilter,
            $groupParams
        );
        foreach ($newShows as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name'], $row['show_name']);
            try {
                $this->enrichShow($row['show_name']); // includes enrichShowSeasons()
            } catch (\Throwable) {
                // Don't let one bad show abort the whole scan
            }
            usleep(150_000);
        }

        // Pass 2: shows that have a TMDB ID but still need per-episode data —
        // either season thumbnails haven't been fetched yet, or new episodes were
        // added since the last enrichment (metadata_fetched_at IS NULL on some rows).
        $needsSeasons = $this->db->query(
            'SELECT DISTINCT show_name, MAX(external_id) as tmdb_id FROM media
             WHERE type = "shows" AND external_source = "tmdb" AND external_id IS NOT NULL
               AND (
                   metadata IS NULL
                   OR metadata NOT LIKE "%season_posters%"
                   OR show_name IN (
                       SELECT DISTINCT show_name FROM media
                       WHERE type = "shows" AND metadata_fetched_at IS NULL
                   )
               )' . $groupFilter . '
             GROUP BY show_name',
            $groupParams
        );
        foreach ($needsSeasons as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name'], $row['show_name']);
            try {
                $this->enrichShowSeasons($row['show_name'], (int) $row['tmdb_id']);
                // Stamp any newly-added episodes so they don't trigger this pass on the next scan
                $this->db->execute(
                    'UPDATE media SET metadata_fetched_at = CURRENT_TIMESTAMP
                     WHERE type = "shows" AND show_name = ? AND metadata_fetched_at IS NULL',
                    [$row['show_name']]
                );
            } catch (\Throwable) {
                // Don't let one bad show abort the whole scan
            }
        }
    }

    private function enrichMusic(?callable $onProgress, ?string $filterGroup = null): void
    {
        $groupFilter = $filterGroup ? ' AND author = ?' : '';
        $groupParams = $filterGroup ? [$filterGroup] : [];

        $albums = $this->db->query(
            'SELECT DISTINCT author, series FROM media
             WHERE type = "music" AND author IS NOT NULL AND metadata_fetched_at IS NULL' . $groupFilter,
            $groupParams
        );
        foreach ($albums as $album) {
            if ($onProgress) $onProgress('music', $album['author'], $album['author']);
            try {
                $this->enrichAlbum($album['author'], $album['series']);
            } catch (\Throwable) {
                // Don't let one bad album abort the whole scan
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
                'SELECT * FROM media WHERE type = ? AND metadata_fetched_at IS NULL' . $groupFilter,
                array_merge([$type], $groupParams)
              )
            : $this->db->query(
                'SELECT * FROM media WHERE type = "books" AND metadata_fetched_at IS NULL' . $groupFilter,
                $groupParams
              );
        foreach ($items as $item) {
            if ($onProgress) $onProgress($item['type'], $item['id'], ($item['book_name'] ?? null) ?: ($item['title'] ?? $item['filename'] ?? ''));
            try {
                $this->enrichBook($item);
            } catch (\Throwable) {
                // Don't let one bad book abort the whole scan
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
            'SELECT book_name, author, series, MIN(path) as sample_path FROM media
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
            } catch (\Throwable) {
                // Don't let one bad audiobook abort the whole scan
            }
            usleep(250_000);
        }

        // After all books are enriched, enrich any series that have no series_meta yet.
        $seriesList = $this->db->query(
            'SELECT DISTINCT series, author FROM media
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
            } catch (\Throwable) {
                // Don't let one bad series abort the whole scan
            }
            usleep(250_000);
        }
    }

    // ── Per-item enrichment ───────────────────────────────────────────────

    private function enrichMovie(array $item): void
    {
        $rawTitle    = $item['title'] ?? $item['filename'];
        $searchTitle = $this->cleanMovieSearchTitle($rawTitle);
        $meta        = $this->tmdb->searchMovie($searchTitle, $item['year']);
        $this->applyToSingle($item['id'], $meta, true);
        $this->renamer?->renameItem($item['id']);
    }

    private function cleanMovieSearchTitle(string $title): string
    {
        $clean = str_replace(['.', '_'], ' ', $title);
        $clean = preg_replace(
            '/\s+\b(480p|576p|720p|1080p|2160p|4K|UHD|BluRay|Blu-Ray|BDRip|BRRip|WEB[-.]?DL|WEBRip|HDTV|DVDRip|HDRip|x264|x265|H\.?264|H\.?265|HEVC|AVC|AAC|AC3|DTS|HDR|SDR|NF|AMZN|DSNP|REPACK|PROPER|EXTENDED|UNRATED|THEATRICAL|REMUX)\b.*$/i',
            '',
            $clean
        );
        // Strip trailing year (passed separately to searchMovie).
        // Require at least one space before the year so titles like "1917" or
        // "2001: A Space Odyssey" are not accidentally truncated to empty strings.
        $clean = preg_replace('/\s+\b(19|20)\d{2}\b\s*$/', '', $clean);
        return trim($clean);
    }

    private function enrichShow(string $showName): void
    {
        $meta = $this->tmdb->searchShow($showName);
        $this->applyToShow($showName, $meta, true);

        if (($meta['external_id'] ?? null) && ($meta['external_source'] ?? null) === 'tmdb') {
            $this->enrichShowSeasons($showName, (int) $meta['external_id']);
        }

        $this->renamer?->renameShowEpisodes($showName);
    }

    private function enrichShowSeasons(string $showName, int $tmdbId): void
    {
        $seasons = $this->db->query(
            'SELECT DISTINCT season FROM media
             WHERE type = "shows" AND show_name = ? AND season IS NOT NULL
             ORDER BY season',
            [$showName]
        );

        $seasonPosters = [];

        foreach ($seasons as $row) {
            $n          = (int) $row['season'];
            $seasonData = $this->tmdb->fetchSeasonDetails($tmdbId, $n);

            if ($seasonData) {
                // Season poster
                if ($seasonData['poster_url']) {
                    $poster = $this->downloadCover($seasonData['poster_url'], "season_{$tmdbId}_{$n}");
                    if ($poster) $seasonPosters[$n] = $poster;
                }

                // Episode titles and stills — match by show_name + season + episode number
                foreach ($seasonData['episodes'] as $ep) {
                    $still = null;
                    if ($ep['still_url']) {
                        $still = $this->downloadCover($ep['still_url'], "ep_{$tmdbId}_{$n}_{$ep['number']}");
                    }
                    $this->db->execute(
                        'UPDATE media SET
                            title = COALESCE(:title, title),
                            still = COALESCE(:still, still)
                         WHERE type = "shows" AND show_name = :show AND season = :season AND episode = :episode',
                        [
                            'title'   => $ep['name'] ?? null,
                            'still'   => $still,
                            'show'    => $showName,
                            'season'  => $n,
                            'episode' => $ep['number'],
                        ]
                    );
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
        $meta = $this->musicBrainz->searchRelease($artist, $album);
        $this->applyToAlbum($artist, $album, $meta, true);
        $this->renamer?->renameAlbumTracks($artist, $album ?? '');
    }

    private function enrichBook(array $item): void
    {
        // Prefer the directory-derived book_name (e.g. "The Way of Kings") over the
        // filename-derived title (often just "book" or a generic placeholder).
        $raw   = ($item['book_name'] ?? null) ?: ($item['title'] ?? $item['filename']);
        $title = $this->cleanBookSearchTitle($raw, $item['author'] ?? null);
        $meta  = $this->openLibrary->search($title, $item['author']);
        $this->applyToSingle($item['id'], $meta, true);
        $this->renamer?->renameItem($item['id']);
    }

    private function enrichAudiobook(?string $bookName, ?string $author, ?string $cleanTitle = null): void
    {
        if (!$bookName) return;
        $title = $cleanTitle ?? $bookName;
        // Prefer Audnexus when the title/filename contains an ASIN; otherwise use OpenLibrary.
        $meta = $this->audnexus->search($title, $author)
            ?? $this->openLibrary->search($title, $author);
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
                series_order        = COALESCE(:series_order, series_order),
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
                'series_order'    => $meta['series_order'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'id'              => $id,
            ]
        );
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
             WHERE type = "shows" AND show_name = :show_name',
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
        $this->db->execute(
            'UPDATE media SET
                description         = ' . $w('description', ':description') . ',
                poster              = COALESCE(:poster, poster),
                external_id         = ' . $w('external_id', ':external_id') . ',
                external_source     = ' . $w('external_source', ':external_source') . ',
                year                = ' . $w('year', ':year') . ',
                series_order        = COALESCE(:series_order, series_order),
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE type = "audiobooks" AND book_name = :book_name',
            [
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'year'            => $meta['year'] ?? null,
                'series_order'    => $meta['series_order'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'book_name'       => $bookName,
            ]
        );

        // Title only makes sense at the whole-book level, not for individual chapters.
        // Only overwrite it when there is exactly one file for this book_name (single-file
        // audiobook like an M4B), so chapter file titles (track names / "Chapter N") are
        // preserved.
        $fileCount = (int) ($this->db->first(
            'SELECT COUNT(*) as n FROM media WHERE type = "audiobooks" AND book_name = ?',
            [$bookName]
        )['n'] ?? 0);

        if ($overwrite || $fileCount <= 1) {
            $this->db->execute(
                'UPDATE media SET title = ' . $w('title', ':title') . '
                 WHERE type = "audiobooks" AND book_name = :book_name',
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
             WHERE type = "music" AND author = :artist AND (series = :album OR (:album IS NULL AND series IS NULL))',
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
            "SELECT metadata FROM media WHERE type = 'audiobooks' AND series = ? AND external_source = 'audnexus' LIMIT 1",
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
            $this->http->get($url, ['sink' => $diskPath, 'timeout' => 15]);
            return file_exists($diskPath) && filesize($diskPath) > 0 ? $webPath : null;
        } catch (\Throwable) {
            @unlink($diskPath);
            return null;
        }
    }
}
