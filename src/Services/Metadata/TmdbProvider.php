<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class TmdbProvider
{
    private const BASE       = 'https://api.themoviedb.org/3';
    private const IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey
    ) {}

    /** Return up to 6 lightweight results for the search panel UI. */
    public function searchMovieMulti(string $title, ?int $year = null): array
    {
        if (!$this->apiKey) return [];
        try {
            $params = ['api_key' => $this->apiKey, 'query' => $title];
            if ($year) $params['year'] = $year;
            $res  = $this->http->get(self::BASE . '/search/movie', ['query' => $params]);
            $data = json_decode($res->getBody()->getContents(), true);
            return array_map(fn($r) => [
                'id'          => (string) $r['id'],
                'source'      => 'tmdb',
                'title'       => $r['title'] ?? '',
                'year'        => (int) substr($r['release_date'] ?? '', 0, 4) ?: null,
                'description' => $r['overview'] ?? null,
                'poster_url'  => isset($r['poster_path']) ? self::IMAGE_BASE . $r['poster_path'] : null,
            ], array_slice($data['results'] ?? [], 0, 6));
        } catch (GuzzleException) {
            return [];
        }
    }

    public function searchShowMulti(string $name): array
    {
        if (!$this->apiKey) return [];
        try {
            $res  = $this->http->get(self::BASE . '/search/tv', [
                'query' => ['api_key' => $this->apiKey, 'query' => $name],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);
            return array_map(fn($r) => [
                'id'          => (string) $r['id'],
                'source'      => 'tmdb',
                'title'       => $r['name'] ?? '',
                'year'        => (int) substr($r['first_air_date'] ?? '', 0, 4) ?: null,
                'description' => $r['overview'] ?? null,
                'poster_url'  => isset($r['poster_path']) ? self::IMAGE_BASE . $r['poster_path'] : null,
            ], array_slice($data['results'] ?? [], 0, 6));
        } catch (GuzzleException) {
            return [];
        }
    }

    public function fetchMovieById(int $id): ?array
    {
        if (!$this->apiKey) return null;
        try { return $this->movieDetails($id); } catch (GuzzleException) { return null; }
    }

    public function fetchShowById(int $id): ?array
    {
        if (!$this->apiKey) return null;
        try { return $this->showDetails($id); } catch (GuzzleException) { return null; }
    }

    public function searchMovie(string $title, ?int $year = null): ?array
    {
        if (!$this->apiKey) return null;

        try {
            $params = ['api_key' => $this->apiKey, 'query' => $title];
            if ($year) $params['year'] = $year;

            $res  = $this->http->get(self::BASE . '/search/movie', ['query' => $params]);
            $data = json_decode($res->getBody()->getContents(), true);
            $hit  = $data['results'][0] ?? null;

            return $hit ? $this->movieDetails($hit['id']) : null;
        } catch (GuzzleException) {
            return null;
        }
    }

    public function searchShow(string $name): ?array
    {
        if (!$this->apiKey) return null;

        try {
            $res  = $this->http->get(self::BASE . '/search/tv', [
                'query' => ['api_key' => $this->apiKey, 'query' => $name],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);
            $hit  = $data['results'][0] ?? null;

            return $hit ? $this->showDetails($hit['id']) : null;
        } catch (GuzzleException) {
            return null;
        }
    }

    /** Search for a person by name and return their bio + profile photo. */
    public function fetchPerson(string $name): ?array
    {
        if (!$this->apiKey) return null;
        try {
            $res  = $this->http->get(self::BASE . '/search/person', [
                'query' => ['api_key' => $this->apiKey, 'query' => $name],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);
            $hit  = $data['results'][0] ?? null;
            if (!$hit) return null;

            $res2 = $this->http->get(self::BASE . '/person/' . $hit['id'], [
                'query' => ['api_key' => $this->apiKey],
            ]);
            $d = json_decode($res2->getBody()->getContents(), true);

            return [
                'external_id'     => (string) ($d['id'] ?? $hit['id']),
                'external_source' => 'tmdb',
                'bio'             => ($d['biography'] ?? '') !== '' ? $d['biography'] : null,
                'image_url'       => isset($d['profile_path']) ? self::IMAGE_BASE . $d['profile_path'] : null,
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    private function movieDetails(int $id): array
    {
        $res  = $this->http->get(self::BASE . "/movie/$id", [
            'query' => ['api_key' => $this->apiKey, 'append_to_response' => 'credits'],
        ]);
        $d = json_decode($res->getBody()->getContents(), true);

        $cast          = array_slice($d['credits']['cast'] ?? [], 0, 6);
        $directorCrews = array_values(array_filter(
            $d['credits']['crew'] ?? [],
            fn($c) => $c['job'] === 'Director'
        ));
        $directorNames = array_column($directorCrews, 'name');
        $collection    = $d['belongs_to_collection']['name'] ?? null;

        return [
            'external_id'     => (string) $id,
            'external_source' => 'tmdb',
            'title'           => $d['title'] ?? null,
            'description'     => $d['overview'] ?? null,
            'year'            => (int) substr($d['release_date'] ?? '', 0, 4) ?: null,
            'rating'          => $d['vote_average'] ?? null,
            'poster_url'      => isset($d['poster_path']) ? self::IMAGE_BASE . $d['poster_path'] : null,
            // Top-level for easy persistence into media_movies columns
            'director'        => $directorNames[0] ?? null,
            'collection'      => $collection,
            'metadata'        => [
                'cast'       => array_column($cast, 'name'),
                'director'   => $directorNames,
                'genres'     => array_column($d['genres'] ?? [], 'name'),
                'collection' => $collection,
            ],
        ];
    }

    /**
     * Fetch poster + per-episode stills for one season.
     * Returns null on error (season may not exist on TMDB).
     */
    public function fetchSeasonDetails(int $showId, int $season): ?array
    {
        if (!$this->apiKey) return null;

        try {
            $res = $this->http->get(self::BASE . "/tv/$showId/season/$season", [
                'query' => ['api_key' => $this->apiKey],
            ]);
            $d = json_decode($res->getBody()->getContents(), true);

            if (empty($d['episodes'])) return null;

            return [
                'poster_url' => isset($d['poster_path']) ? self::IMAGE_BASE . $d['poster_path'] : null,
                'episodes'   => array_map(fn($e) => [
                    'number'    => (int) $e['episode_number'],
                    'name'      => $e['name'] ?? null,
                    'still_url' => isset($e['still_path'])
                        ? 'https://image.tmdb.org/t/p/w300' . $e['still_path']
                        : null,
                ], $d['episodes']),
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    private function showDetails(int $id): array
    {
        $res = $this->http->get(self::BASE . "/tv/$id", [
            'query' => ['api_key' => $this->apiKey, 'append_to_response' => 'credits'],
        ]);
        $d = json_decode($res->getBody()->getContents(), true);

        $cast = array_slice($d['credits']['cast'] ?? [], 0, 6);

        return [
            'external_id'     => (string) $id,
            'external_source' => 'tmdb',
            'title'           => $d['name'] ?? null,
            'description'     => $d['overview'] ?? null,
            'year'            => (int) substr($d['first_air_date'] ?? '', 0, 4) ?: null,
            'rating'          => $d['vote_average'] ?? null,
            'poster_url'      => isset($d['poster_path']) ? self::IMAGE_BASE . $d['poster_path'] : null,
            'metadata'        => [
                'cast'     => array_column($cast, 'name'),
                'genres'   => array_column($d['genres'] ?? [], 'name'),
                'networks' => array_column($d['networks'] ?? [], 'name'),
                'status'   => $d['status'] ?? null,
            ],
        ];
    }
}
