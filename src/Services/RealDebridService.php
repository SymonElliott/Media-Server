<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\ClientInterface;

class RealDebridService
{
    private const BASE = 'https://api.real-debrid.com/rest/1.0';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    // ── Torrents ──────────────────────────────────────────────────────────

    /** Add a magnet link. Returns the RD torrent ID. */
    public function addMagnet(string $magnet): string
    {
        $res  = $this->post('/torrents/addMagnet', ['magnet' => $magnet]);
        return $res['id'] ?? throw new \RuntimeException('No torrent ID returned from RD');
    }

    /** Select all files in a torrent so RD starts downloading. */
    public function selectFiles(string $torrentId): void
    {
        $this->post('/torrents/selectFiles/' . $torrentId, ['files' => 'all']);
    }

    /** Get full torrent info including status, files, and download links. */
    public function getTorrentInfo(string $torrentId): array
    {
        return $this->get('/torrents/info/' . $torrentId);
    }

    /** Delete a torrent from RD. */
    public function deleteTorrent(string $torrentId): void
    {
        $this->request('DELETE', '/torrents/delete/' . $torrentId);
    }

    // ── Link unrestriction ────────────────────────────────────────────────

    /**
     * Unrestrict a hoster link into a direct download URL.
     * Returns array with 'download', 'filename', 'filesize', 'mimeType'.
     */
    public function unrestrictLink(string $link): array
    {
        return $this->post('/unrestrict/link', ['link' => $link]);
    }

    // ── Account ───────────────────────────────────────────────────────────

    /** Verify the API key is valid. Returns account info or throws. */
    public function getUser(): array
    {
        return $this->get('/user');
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $form = []): array
    {
        return $this->request('POST', $path, $form);
    }

    private function request(string $method, string $path, array $form = []): array
    {
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
        ];
        if ($form) {
            $options['form_params'] = $form;
        }

        $response = $this->http->request($method, self::BASE . $path, $options);
        $body     = (string) $response->getBody();
        $status   = $response->getStatusCode();

        if ($status >= 400) {
            $err = json_decode($body, true);
            throw new \RuntimeException(
                'RD API error ' . $status . ': ' . ($err['error'] ?? $body)
            );
        }

        if ($body === '' || $body === 'null') return [];
        return json_decode($body, true) ?? [];
    }
}
