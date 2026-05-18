<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Database\Connection;
use GuzzleHttp\ClientInterface;

class MetadataService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TmdbProvider $tmdb,
        private readonly MusicBrainzProvider $musicBrainz,
        private readonly OpenLibraryProvider $openLibrary,
        private readonly ClientInterface $http,
        private readonly string $coversDir
    ) {}

    // ── Public API ────────────────────────────────────────────────────────

    /** Return up to 6 search results from the appropriate external provider. */
    public function searchExternal(string $type, string $query): array
    {
        return match ($type) {
            'movies'              => $this->tmdb->searchMovieMulti($query),
            'shows'               => $this->tmdb->searchShowMulti($query),
            'music'               => $this->musicBrainz->searchReleaseMulti($query),
            'books', 'audiobooks' => $this->openLibrary->searchMulti($query),
            default               => [],
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
        } elseif ($item['type'] === 'music') {
            $this->applyToAlbum($item['author'], $item['series'], $meta, true);
        } else {
            $this->applyToSingle($mediaId, $meta, true);
        }
    }

    /** Enrich all items that haven't been fetched yet. Called from bin/scan.php. */
    public function enrichAll(?callable $onProgress = null): void
    {
        $this->enrichMovies($onProgress);
        $this->enrichShows($onProgress);
        $this->enrichMusic($onProgress);
        $this->enrichBooks($onProgress);
    }

    /** Enrich all items of a specific type that haven't been fetched yet. */
    public function enrichType(string $type, ?callable $onProgress = null): void
    {
        match ($type) {
            'movies'     => $this->enrichMovies($onProgress),
            'shows'      => $this->enrichShows($onProgress),
            'music'      => $this->enrichMusic($onProgress),
            'books'      => $this->enrichBooksOfType('books', $onProgress),
            'audiobooks' => $this->enrichBooksOfType('audiobooks', $onProgress),
            default      => null,
        };
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
            'movies'                        => $this->enrichMovie($item),
            'shows'                         => $this->enrichShow($item['show_name']),
            'music'                         => $this->enrichAlbum($item['author'], $item['series']),
            'books', 'audiobooks',
            'cookbooks'                     => $this->enrichBook($item),
            default                         => null,
        };
    }

    // ── Per-type batch enrichment ─────────────────────────────────────────

    private function enrichMovies(?callable $onProgress): void
    {
        $items = $this->db->query(
            'SELECT * FROM media WHERE type = "movies" AND metadata_fetched_at IS NULL'
        );
        foreach ($items as $item) {
            if ($onProgress) $onProgress('movies', $item['id']);
            $this->enrichMovie($item);
            usleep(150_000); // stay well under TMDB rate limit
        }
    }

    private function enrichShows(?callable $onProgress): void
    {
        // Pass 1: shows with no metadata at all
        $newShows = $this->db->query(
            'SELECT DISTINCT show_name FROM media
             WHERE type = "shows" AND show_name IS NOT NULL
               AND show_name NOT IN (
                   SELECT DISTINCT show_name FROM media
                   WHERE type = "shows" AND metadata_fetched_at IS NOT NULL
               )'
        );
        foreach ($newShows as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name']);
            $this->enrichShow($row['show_name']); // includes enrichShowSeasons()
            usleep(150_000);
        }

        // Pass 2: shows that have a TMDB ID but no season thumbnails yet
        $needsSeasons = $this->db->query(
            'SELECT DISTINCT show_name, MAX(external_id) as tmdb_id FROM media
             WHERE type = "shows" AND external_source = "tmdb" AND external_id IS NOT NULL
               AND (metadata IS NULL OR metadata NOT LIKE "%season_posters%")
             GROUP BY show_name'
        );
        foreach ($needsSeasons as $row) {
            if ($onProgress) $onProgress('shows', $row['show_name']);
            $this->enrichShowSeasons($row['show_name'], (int) $row['tmdb_id']);
        }
    }

    private function enrichMusic(?callable $onProgress): void
    {
        $albums = $this->db->query(
            'SELECT DISTINCT author, series FROM media
             WHERE type = "music" AND author IS NOT NULL AND metadata_fetched_at IS NULL'
        );
        foreach ($albums as $album) {
            if ($onProgress) $onProgress('music', $album['author']);
            $this->enrichAlbum($album['author'], $album['series']);
            sleep(1); // MusicBrainz: 1 req/second
        }
    }

    private function enrichBooks(?callable $onProgress): void
    {
        $this->enrichBooksOfType(null, $onProgress);
    }

    private function enrichBooksOfType(?string $type, ?callable $onProgress): void
    {
        $items = $type
            ? $this->db->query(
                'SELECT * FROM media WHERE type = ? AND metadata_fetched_at IS NULL',
                [$type]
              )
            : $this->db->query(
                'SELECT * FROM media WHERE type IN ("books", "audiobooks") AND metadata_fetched_at IS NULL'
              );
        foreach ($items as $item) {
            if ($onProgress) $onProgress($item['type'], $item['id']);
            $this->enrichBook($item);
            usleep(250_000);
        }
    }

    // ── Per-item enrichment ───────────────────────────────────────────────

    private function enrichMovie(array $item): void
    {
        $rawTitle    = $item['title'] ?? $item['filename'];
        $searchTitle = $this->cleanMovieSearchTitle($rawTitle);
        $meta        = $this->tmdb->searchMovie($searchTitle, $item['year']);
        $this->applyToSingle($item['id'], $meta);
    }

    private function cleanMovieSearchTitle(string $title): string
    {
        $clean = str_replace(['.', '_'], ' ', $title);
        $clean = preg_replace(
            '/\s+\b(480p|576p|720p|1080p|2160p|4K|UHD|BluRay|Blu-Ray|BDRip|BRRip|WEB[-.]?DL|WEBRip|HDTV|DVDRip|HDRip|x264|x265|H\.?264|H\.?265|HEVC|AVC|AAC|AC3|DTS|HDR|SDR|NF|AMZN|DSNP|REPACK|PROPER|EXTENDED|UNRATED|THEATRICAL|REMUX)\b.*$/i',
            '',
            $clean
        );
        // Strip trailing year — passed separately to searchMovie
        $clean = preg_replace('/\s*\b(19|20)\d{2}\b.*$/', '', $clean);
        return trim($clean);
    }

    private function enrichShow(string $showName): void
    {
        $meta = $this->tmdb->searchShow($showName);
        $this->applyToShow($showName, $meta);

        if (($meta['external_id'] ?? null) && ($meta['external_source'] ?? null) === 'tmdb') {
            $this->enrichShowSeasons($showName, (int) $meta['external_id']);
        }
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
        $this->applyToAlbum($artist, $album, $meta);
    }

    private function enrichBook(array $item): void
    {
        $meta = $this->openLibrary->search($item['title'] ?? $item['filename'], $item['author']);
        $this->applyToSingle($item['id'], $meta);
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
                metadata            = :metadata,
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE type = "music" AND author = :artist AND (series = :album OR (:album IS NULL AND series IS NULL))',
            [
                'description'     => $meta['description'] ?? null,
                'poster'          => $poster,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'metadata'        => json_encode($meta['metadata'] ?? []),
                'artist'          => $artist,
                'album'           => $album,
            ]
        );
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
