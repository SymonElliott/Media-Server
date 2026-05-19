<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Audnexus API (api.audnex.us) — ASIN-based lookups only.
 * There is no title-search endpoint; all lookups require an Audible ASIN.
 */
class AudnexusProvider
{
    private const BASE = 'https://api.audnex.us';
    private const ASIN_RE = '/\bB[0-9A-Z]{9}\b/';

    public function __construct(private readonly ClientInterface $http) {}

    /**
     * For the search-panel UI: if the query looks like an ASIN return that book,
     * otherwise return empty (no title-search endpoint exists).
     */
    public function searchMulti(string $query, ?string $author = null): array
    {
        if (preg_match(self::ASIN_RE, $query, $m)) {
            $result = $this->fetchByAsin($m[0]);
            if ($result) {
                return [[
                    'id'          => $result['external_id'],
                    'source'      => 'audnexus',
                    'title'       => $result['title'] ?? '',
                    'year'        => $result['year'] ?? null,
                    'description' => null,
                    'poster_url'  => $result['poster_url'] ?? null,
                ]];
            }
        }
        return [];
    }

    /**
     * Return full metadata for enrichment.
     * Extracts ASIN from $title string, or uses $asin directly. Returns null if no ASIN found.
     */
    public function search(string $title, ?string $author = null, ?string $asin = null): ?array
    {
        if (!$asin) {
            preg_match(self::ASIN_RE, $title, $m);
            $asin = $m[0] ?? null;
        }
        return $asin ? $this->fetchByAsin($asin) : null;
    }

    /** Fetch full book details by ASIN. */
    public function fetchByAsin(string $asin): ?array
    {
        try {
            $res = $this->http->get(self::BASE . '/books/' . rawurlencode($asin));
            $d   = json_decode($res->getBody()->getContents(), true);

            if (empty($d) || isset($d['statusCode']) || isset($d['error'])) return null;

            $genres = array_values(array_map(
                fn($g) => $g['name'],
                array_filter($d['genres'] ?? [], fn($g) => ($g['type'] ?? '') === 'genre')
            ));

            $year = isset($d['releaseDate']) ? ((int) substr($d['releaseDate'], 0, 4) ?: null) : null;

            $seriesOrder = null;
            if (!empty($d['seriesPrimary']['position'])) {
                $seriesOrder = (float) $d['seriesPrimary']['position'];
            }

            $description = null;
            if (!empty($d['summary'])) {
                $description = strip_tags((string) $d['summary']);
            } elseif (!empty($d['description'])) {
                $description = $d['description'];
            }

            $authorAsin = $d['authors'][0]['asin'] ?? null;

            return [
                'external_id'     => $d['asin'] ?? $asin,
                'external_source' => 'audnexus',
                'title'           => $d['title'] ?? null,
                'description'     => $description,
                'year'            => $year,
                'poster_url'      => $d['image'] ?? null,
                'series_order'    => $seriesOrder,
                'author_asin'     => $authorAsin,
                'metadata'        => [
                    'genres'      => $genres,
                    'narrators'   => array_column($d['narrators'] ?? [], 'name'),
                    'author_asin' => $authorAsin,
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    /** Fetch author details (name + photo) by ASIN. */
    public function fetchAuthor(string $asin): ?array
    {
        try {
            $res = $this->http->get(self::BASE . '/authors/' . rawurlencode($asin));
            $d   = json_decode($res->getBody()->getContents(), true);

            if (empty($d) || isset($d['statusCode']) || isset($d['error'])) return null;

            return [
                'asin'        => $d['asin'] ?? $asin,
                'name'        => $d['name'] ?? null,
                'image_url'   => $d['image'] ?? null,
                'description' => $d['description'] ?? null,
            ];
        } catch (GuzzleException) {
            return null;
        }
    }
}
