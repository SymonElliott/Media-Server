<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class MusicBrainzProvider
{
    private const BASE     = 'https://musicbrainz.org/ws/2';
    private const CAA_BASE = 'https://coverartarchive.org';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $userAgent
    ) {}

    /**
     * Search and return up to 6 release candidates for the manual-match overlay.
     */
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
                'id'          => $r['release-group']['id'] ?? $r['id'],
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

    /**
     * Fetch a release group by MBID (used by manual applyMatch).
     */
    public function fetchRelease(string $mbid): ?array
    {
        // Try as release-group first, then fall back to release
        $rg = $this->fetchReleaseGroup($mbid);
        if ($rg) return $rg;

        try {
            $res = $this->http->get(self::BASE . "/release/$mbid", [
                'query'   => ['fmt' => 'json', 'inc' => 'artist-credits+labels'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            $hit      = json_decode($res->getBody()->getContents(), true);
            $coverUrl = $this->coverUrl('release', $mbid);
            return [
                'external_id'     => $mbid,
                'external_source' => 'musicbrainz',
                'title'           => $hit['title'] ?? null,
                'description'     => null,
                'year'            => (int) ($hit['date'] ?? '') ?: null,
                'poster_url'      => $coverUrl,
                'metadata'        => [
                    'artist' => $hit['artist-credit'][0]['artist']['name'] ?? null,
                    'label'  => $hit['label-info'][0]['label']['name'] ?? null,
                    'genres' => [],
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    /**
     * Search for an album, enrich via release group (genres, first-release-date,
     * Wikipedia description, release-group cover art).
     * MusicBrainz allows 1 req/second — caller must add outer rate-limiting.
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

            $releaseMbid      = $hit['id'];
            $releaseGroupMbid = $hit['release-group']['id'] ?? null;

            // Fetch release group for genres, first-release-date, and Wikipedia URL
            $genres      = [];
            $year        = (int) ($hit['date'] ?? '') ?: null;
            $description = null;
            $albumType   = $hit['release-group']['primary-type'] ?? null;

            if ($releaseGroupMbid) {
                usleep(1_100_000); // MB rate limit: 1 req/sec
                $rg = $this->fetchReleaseGroupRaw($releaseGroupMbid);
                if ($rg) {
                    $firstYear = (int) ($rg['first-release-date'] ?? '') ?: null;
                    if ($firstYear) $year = $firstYear;

                    $albumType = $rg['primary-type'] ?? $albumType;

                    // Genres sorted by vote count, top 5
                    $genreList = $rg['genres'] ?? $rg['tags'] ?? [];
                    usort($genreList, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
                    $genres = array_slice(array_column($genreList, 'name'), 0, 5);

                    // Wikipedia description for the album
                    foreach ($rg['relations'] ?? [] as $rel) {
                        if (($rel['type'] ?? '') === 'wikipedia' && isset($rel['url']['resource'])) {
                            $slug        = rawurlencode(basename(parse_url($rel['url']['resource'], PHP_URL_PATH)));
                            $description = $this->fetchWikipediaExtract($slug);
                            break;
                        }
                    }
                }
            }

            // Cover art: try release-group level first (covers all editions), then release
            $coverUrl = ($releaseGroupMbid ? $this->coverUrl('release-group', $releaseGroupMbid) : null)
                     ?? $this->coverUrl('release', $releaseMbid);

            return [
                'external_id'     => $releaseGroupMbid ?? $releaseMbid,
                'external_source' => 'musicbrainz',
                'title'           => $hit['title'] ?? $album,
                'description'     => $description,
                'year'            => $year,
                'poster_url'      => $coverUrl,
                'metadata'        => [
                    'artist'       => $artist,
                    'label'        => $hit['label-info'][0]['label']['name'] ?? null,
                    'genres'       => $genres,
                    'type'         => $albumType,
                    'release_mbid' => $releaseMbid,   // stored for per-track ordering lookups
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    /**
     * Fetch the track listing for a specific release (not release-group).
     *
     * Returns a map of  normalised-title → series_order (float).
     * For single-disc releases series_order is just the track position (1, 2, 3…).
     * For multi-disc releases it is  disc * 100 + track  (e.g. disc 2 track 5 → 205).
     *
     * Normalisation: lowercase, strip punctuation/symbols, collapse whitespace —
     * so "Stairway to Heaven" and "01 - Stairway to Heaven" both map to
     * "stairway to heaven".
     */
    public function fetchReleaseTracks(string $releaseMbid): array
    {
        try {
            $res  = $this->http->get(self::BASE . "/release/{$releaseMbid}", [
                'query'   => ['inc' => 'recordings+media', 'fmt' => 'json'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            $data = json_decode($res->getBody()->getContents(), true);

            $mediaList  = $data['media'] ?? [];
            $multiDisc  = count($mediaList) > 1;
            $trackMap   = [];

            foreach ($mediaList as $medium) {
                $discPos = (int) ($medium['position'] ?? 1);
                foreach ($medium['tracks'] ?? [] as $track) {
                    $pos   = (int) ($track['position'] ?? 0);
                    $order = $multiDisc
                        ? (float) ($discPos * 100 + $pos)
                        : (float) $pos;
                    $title = $track['title'] ?? '';
                    if ($title !== '') {
                        $trackMap[$this->normaliseTitle($title)] = $order;
                    }
                }
            }
            return $trackMap;
        } catch (GuzzleException) {
            return [];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Normalise a track title for fuzzy matching against filenames.
     * Strips leading track numbers, lowercases, removes punctuation.
     */
    public function normaliseTitle(string $s): string
    {
        $s = preg_replace('/^\d+\s*[-._]\s*/', '', $s);          // strip "01 - " prefix
        $s = strtolower($s);
        $s = preg_replace('/[^\w\s]/u', '', $s);                  // drop punctuation
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private function fetchReleaseGroup(string $mbid): ?array
    {
        $raw = $this->fetchReleaseGroupRaw($mbid);
        if (!$raw) return null;

        $genreList = $raw['genres'] ?? $raw['tags'] ?? [];
        usort($genreList, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        $genres = array_slice(array_column($genreList, 'name'), 0, 5);

        $description = null;
        foreach ($raw['relations'] ?? [] as $rel) {
            if (($rel['type'] ?? '') === 'wikipedia' && isset($rel['url']['resource'])) {
                $slug        = rawurlencode(basename(parse_url($rel['url']['resource'], PHP_URL_PATH)));
                $description = $this->fetchWikipediaExtract($slug);
                break;
            }
        }

        $coverUrl = $this->coverUrl('release-group', $mbid);

        return [
            'external_id'     => $mbid,
            'external_source' => 'musicbrainz',
            'title'           => $raw['title'] ?? null,
            'description'     => $description,
            'year'            => (int) ($raw['first-release-date'] ?? '') ?: null,
            'poster_url'      => $coverUrl,
            'metadata'        => [
                'genres' => $genres,
                'type'   => $raw['primary-type'] ?? null,
            ],
        ];
    }

    private function fetchReleaseGroupRaw(string $mbid): ?array
    {
        try {
            $res = $this->http->get(self::BASE . "/release-group/$mbid", [
                'query'   => ['inc' => 'tags+genres+url-rels', 'fmt' => 'json'],
                'headers' => ['User-Agent' => $this->userAgent],
            ]);
            return json_decode($res->getBody()->getContents(), true) ?: null;
        } catch (GuzzleException) {
            return null;
        }
    }

    private function coverUrl(string $entity, string $mbid): ?string
    {
        // Return the URL directly — downloadCover() will attempt the fetch and
        // discard any non-200 response (e.g. CAA 404), so no pre-flight request needed.
        return self::CAA_BASE . "/$entity/$mbid/front-500";
    }

    private function fetchWikipediaExtract(string $encodedSlug): ?string
    {
        try {
            $res  = $this->http->get(
                'https://en.wikipedia.org/api/rest_v1/page/summary/' . $encodedSlug,
                ['headers' => ['User-Agent' => $this->userAgent], 'timeout' => 8]
            );
            $data = json_decode($res->getBody()->getContents(), true);
            return ($data['extract'] ?? null) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
