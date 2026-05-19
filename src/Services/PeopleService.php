<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Services\Metadata\AudnexusProvider;
use App\Services\Metadata\OpenLibraryProvider;
use App\Services\Metadata\TmdbProvider;
use GuzzleHttp\ClientInterface;

class PeopleService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TmdbProvider $tmdb,
        private readonly AudnexusProvider $audnexus,
        private readonly OpenLibraryProvider $openLibrary,
        private readonly ClientInterface $http,
        private readonly string $coversDir
    ) {}

    public static function slugify(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Populate the people table from existing media rows.
     * Extracts authors/artists from media.author and cast/directors from metadata JSON.
     * Safe to call repeatedly — uses INSERT OR IGNORE.
     */
    public function syncFromMedia(): void
    {
        $rows = $this->db->query(
            'SELECT DISTINCT author, type FROM media WHERE author IS NOT NULL AND author != ""'
        );
        foreach ($rows as $row) {
            $role = $row['type'] === 'music' ? 'artist' : 'author';
            $this->upsertPerson($row['author'], $role);
        }

        $mediaRows = $this->db->query(
            'SELECT metadata FROM media
             WHERE type IN ("movies", "shows")
               AND metadata IS NOT NULL AND metadata NOT IN ("{}", "")'
        );
        foreach ($mediaRows as $row) {
            $meta = json_decode($row['metadata'], true);
            foreach (array_merge($meta['cast'] ?? [], $meta['director'] ?? []) as $name) {
                if (is_string($name) && $name !== '') $this->upsertPerson($name, 'cast');
            }
        }
    }

    private function upsertPerson(string $name, string $role): void
    {
        $name = trim($name);
        $slug = self::slugify($name);
        if ($slug === '') return;
        $this->db->execute(
            'INSERT OR IGNORE INTO people (name, slug, role) VALUES (?, ?, ?)',
            [$name, $slug, $role]
        );
    }

    /** Enrich all people that have not been enriched yet. */
    public function enrichAll(?callable $onProgress = null): void
    {
        $people = $this->db->query(
            'SELECT * FROM people WHERE metadata_fetched_at IS NULL ORDER BY role, name'
        );
        foreach ($people as $person) {
            if ($onProgress) $onProgress($person['name']);
            $this->enrichPerson($person);
        }
    }

    /** Re-enrich a single person by ID. */
    public function refreshPerson(int $id): void
    {
        $this->db->execute('UPDATE people SET metadata_fetched_at = NULL WHERE id = ?', [$id]);
        $person = $this->db->first('SELECT * FROM people WHERE id = ?', [$id]);
        if ($person) $this->enrichPerson($person);
    }

    private function enrichPerson(array $person): void
    {
        $meta = null;

        if ($person['role'] === 'cast') {
            $meta = $this->tmdb->fetchPerson($person['name']);
            usleep(150_000);
        } elseif ($person['role'] === 'author') {
            // Prefer Audnexus when we have a stored author ASIN from series enrichment
            $seriesRow = $this->db->first(
                'SELECT author_asin FROM series_meta WHERE author = ? AND author_asin IS NOT NULL LIMIT 1',
                [$person['name']]
            );
            if ($seriesRow) {
                $d = $this->audnexus->fetchAuthor($seriesRow['author_asin']);
                if ($d) {
                    $meta = [
                        'external_id'     => $d['asin'],
                        'external_source' => 'audnexus',
                        'bio'             => $d['description'] ?? null,
                        'image_url'       => $d['image_url'],
                    ];
                }
            }
            if (!$meta) {
                $meta = $this->openLibrary->fetchAuthorByName($person['name']);
                usleep(250_000);
            }
        }
        } elseif ($person['role'] === 'artist') {
            $meta = $this->fetchWikipediaSummary($person['name']);
            usleep(100_000);

        $image = null;
        if ($meta && ($meta['image_url'] ?? null)) {
            $image = $this->downloadImage($meta['image_url'], 'person_' . $person['id']);
        }

        $this->db->execute(
            'UPDATE people SET
                bio                 = COALESCE(:bio, bio),
                image               = COALESCE(:image, image),
                external_id         = COALESCE(:external_id, external_id),
                external_source     = COALESCE(:external_source, external_source),
                metadata_fetched_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'bio'             => $meta['bio'] ?? null,
                'image'           => $image,
                'external_id'     => $meta['external_id'] ?? null,
                'external_source' => $meta['external_source'] ?? null,
                'id'              => $person['id'],
            ]
        );
    }

    private function fetchWikipediaSummary(string $name): ?array
    {
        try {
            $slug = str_replace(' ', '_', $name);
            $res  = $this->http->get(
                'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($slug),
                ['headers' => ['User-Agent' => 'MediaServer/1.0 (personal)'], 'timeout' => 10]
            );
            $data = json_decode($res->getBody()->getContents(), true);
            return [
                'bio'       => $data['extract'] ?? null,
                'image_url' => $data['thumbnail']['source'] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function downloadImage(string $url, string $key): ?string
    {
        if (!is_dir($this->coversDir)) mkdir($this->coversDir, 0755, true);

        $filename = substr(md5($key), 0, 16) . '.jpg';
        $diskPath = $this->coversDir . '/' . $filename;
        $webPath  = '/covers/' . $filename;

        if (file_exists($diskPath)) return $webPath;

        try {
            $this->http->get($url, ['sink' => $diskPath, 'timeout' => 15]);
            return file_exists($diskPath) && filesize($diskPath) > 0 ? $webPath : null;
        } catch (\Throwable) {
            @unlink($diskPath);
            return null;
        }
    }
}
