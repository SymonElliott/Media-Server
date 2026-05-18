<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class MusicBrainzProvider
{
    private const BASE     = 'https://musicbrainz.org/ws/2';
    private const CAA_BASE = 'https://coverartarchive.org/release';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $userAgent
    ) {}

    public function searchReleaseMulti(string $artist, ?string $album = null): array
    {
        try {
            $query = $album
                ? sprintf('release:"%s" AND artist:"%s"', addslashes($album), addslashes($artist))
                : sprintf('artist:"%s"', addslashes($artist));
            $res  = $this->http->get(self::BASE . '/release', [
                'query'   => ['query' => $query, 'limit' => 6, 'fmt' => 'json'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);
            return array_map(fn($r) => [
                'id'          => $r['id'],
                'source'      => 'musicbrainz',
                'title'       => $r['title'] ?? '',
                'year'        => (int) ($r['date'] ?? '') ?: null,
                'description' => $r['artist-credit'][0]['artist']['name'] ?? null,
                'poster_url'  => null,
            ], $data['releases'] ?? []);
        } catch (GuzzleException) {
            return [];
        }
    }

    public function fetchRelease(string $mbid): ?array
    {
        try {
            $res = $this->http->get(self::BASE . "/release/$mbid", [
                'query'   => ['fmt' => 'json', 'inc' => 'artist-credits+labels'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            $hit      = json_decode($res->getBody()->getContents(), true);
            $coverUrl = $this->coverUrl($mbid);
            return [
                'external_id'     => $mbid,
                'external_source' => 'musicbrainz',
                'title'           => $hit['title'] ?? null,
                'description'     => null,
                'year'            => (int) ($hit['date'] ?? '') ?: null,
                'rating'          => null,
                'poster_url'      => $coverUrl,
                'metadata'        => [
                    'artist' => $hit['artist-credit'][0]['artist']['name'] ?? null,
                    'label'  => $hit['label-info'][0]['label']['name'] ?? null,
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    /**
     * Search for a release (album) by artist + album name.
     * MusicBrainz allows 1 req/second — caller must rate-limit.
     */
    public function searchRelease(string $artist, ?string $album): ?array
    {
        try {
            $query = $album
                ? sprintf('release:"%s" AND artist:"%s"', addslashes($album), addslashes($artist))
                : sprintf('artist:"%s"', addslashes($artist));

            $res  = $this->http->get(self::BASE . '/release', [
                'query'   => ['query' => $query, 'limit' => 1, 'fmt' => 'json'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);
            $hit  = $data['releases'][0] ?? null;

            if (!$hit) return null;

            $mbid      = $hit['id'];
            $coverUrl  = $this->coverUrl($mbid);

            return [
                'external_id'     => $mbid,
                'external_source' => 'musicbrainz',
                'title'           => $hit['title'] ?? $album,
                'description'     => null,
                'year'            => (int) ($hit['date'] ?? '') ?: null,
                'rating'          => null,
                'poster_url'      => $coverUrl,
                'metadata'        => [
                    'artist' => $artist,
                    'label'  => $hit['label-info'][0]['label']['name'] ?? null,
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    private function coverUrl(string $mbid): ?string
    {
        try {
            // CAA returns a 307 redirect to the actual image; just return the front URL
            $res = $this->http->get(self::CAA_BASE . "/$mbid/front-250", [
                'allow_redirects' => true,
                'headers'         => ['User-Agent' => $this->userAgent],
            ]);

            return $res->getStatusCode() === 200
                ? self::CAA_BASE . "/$mbid/front-500"
                : null;
        } catch (GuzzleException) {
            return null;
        }
    }
}
