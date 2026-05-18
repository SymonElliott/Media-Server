<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class OpenLibraryProvider
{
    private const SEARCH   = 'https://openlibrary.org/search.json';
    private const COVER    = 'https://covers.openlibrary.org/b/id';

    public function __construct(private readonly ClientInterface $http) {}

    public function searchMulti(string $title, ?string $author = null): array
    {
        try {
            $limit  = $author ? 12 : 6;
            $params = ['title' => $title, 'limit' => $limit, 'fields' => 'key,title,author_name,first_publish_year,cover_i'];
            $res    = $this->http->get(self::SEARCH, ['query' => $params]);
            $data   = json_decode($res->getBody()->getContents(), true);
            $docs   = $data['docs'] ?? [];

            if ($author) {
                $norm = fn(string $s) => strtolower(preg_replace('/\s+/', ' ', trim($s)));
                $na   = $norm($author);
                usort($docs, function ($a, $b) use ($norm, $na) {
                    $aMatch = isset($a['author_name']) && array_filter($a['author_name'], fn($n) => str_contains($norm($n), $na) || str_contains($na, $norm($n)));
                    $bMatch = isset($b['author_name']) && array_filter($b['author_name'], fn($n) => str_contains($norm($n), $na) || str_contains($na, $norm($n)));
                    return (int) $bMatch <=> (int) $aMatch;
                });
                $docs = array_slice($docs, 0, 6);
            }

            return array_map(fn($r) => [
                'id'          => ltrim($r['key'] ?? '', '/works/'),
                'source'      => 'openlibrary',
                'title'       => $r['title'] ?? '',
                'year'        => $r['first_publish_year'] ?? null,
                'description' => isset($r['author_name'][0]) ? 'by ' . $r['author_name'][0] : null,
                'poster_url'  => isset($r['cover_i']) ? self::COVER . "/{$r['cover_i']}-M.jpg" : null,
            ], $docs);
        } catch (GuzzleException) {
            return [];
        }
    }

    public function fetchByKey(string $key): ?array
    {
        try {
            $path = str_starts_with($key, '/') ? $key : '/works/' . $key;
            $res  = $this->http->get('https://openlibrary.org' . $path . '.json');
            $d    = json_decode($res->getBody()->getContents(), true);

            $coverId = null;
            try {
                $eRes    = $this->http->get(self::SEARCH, ['query' => ['q' => 'key:' . $path, 'fields' => 'cover_i', 'limit' => 1]]);
                $eData   = json_decode($eRes->getBody()->getContents(), true);
                $coverId = $eData['docs'][0]['cover_i'] ?? null;
            } catch (GuzzleException) {}

            return [
                'external_id'     => ltrim($path, '/works/'),
                'external_source' => 'openlibrary',
                'title'           => $d['title'] ?? null,
                'description'     => is_string($d['description'] ?? null)
                                        ? $d['description']
                                        : ($d['description']['value'] ?? null),
                'year'            => null,
                'rating'          => null,
                'poster_url'      => $coverId ? self::COVER . "/$coverId-L.jpg" : null,
                'metadata'        => [],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }

    public function search(string $title, ?string $author = null): ?array
    {
        try {
            $limit  = $author ? 10 : 5;
            $params = ['title' => $title, 'limit' => $limit, 'fields' => 'key,title,author_name,first_publish_year,subject,description,cover_i'];

            $res  = $this->http->get(self::SEARCH, ['query' => $params]);
            $data = json_decode($res->getBody()->getContents(), true);
            $docs = $data['docs'] ?? [];

            if ($author && $docs) {
                $norm = fn(string $s) => strtolower(preg_replace('/\s+/', ' ', trim($s)));
                $na   = $norm($author);
                foreach ($docs as $doc) {
                    $names = $doc['author_name'] ?? [];
                    foreach ($names as $n) {
                        if (str_contains($norm($n), $na) || str_contains($na, $norm($n))) {
                            $hit = $doc;
                            break 2;
                        }
                    }
                }
            }

            $hit ??= $docs[0] ?? null;
            if (!$hit) return null;

            $coverId = $hit['cover_i'] ?? null;

            return [
                'external_id'     => ltrim($hit['key'] ?? '', '/works/'),
                'external_source' => 'openlibrary',
                'title'           => $hit['title'] ?? $title,
                'description'     => is_string($hit['description'] ?? null)
                                        ? $hit['description']
                                        : null,
                'year'            => $hit['first_publish_year'] ?? null,
                'rating'          => null,
                'poster_url'      => $coverId ? self::COVER . "/$coverId-L.jpg" : null,
                'metadata'        => [
                    'author'   => $hit['author_name'][0] ?? $author,
                    'subjects' => array_slice($hit['subject'] ?? [], 0, 5),
                ],
            ];
        } catch (GuzzleException) {
            return null;
        }
    }
}
