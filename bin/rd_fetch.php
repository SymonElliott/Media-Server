#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background Real-Debrid fetch script.
 *
 * Usage (torrent):  php rd_fetch.php <torrent_id>
 * Usage (direct):   php rd_fetch.php <download_id> <direct_url> <category>
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$id          = $argv[1] ?? null;
$directUrl   = $argv[2] ?? null;
$directCat   = $argv[3] ?? null;

if (!$id) exit(1);

$db          = new App\Database\Connection($root . '/storage/db/media.sqlite');
$settings    = new App\Services\Settings($db);
$libraryPath = $root . '/library';
$http        = new GuzzleHttp\Client(['timeout' => 30, 'http_errors' => false]);
$rd          = new App\Services\RealDebridService($http, $settings->getEnv('REAL_DEBRID_API_KEY'));

$audioExts  = ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav'];
$audioBookExts = ['m4b'];
$ebookExts  = ['epub', 'pdf', 'mobi', 'azw', 'azw3'];
$videoExts  = ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'ts', 'm2ts'];

function setStatus(App\Database\Connection $db, string $id, string $status, array $extra = []): void
{
    $sets   = ['status = ?', 'updated_at = CURRENT_TIMESTAMP'];
    $params = [$status];
    foreach ($extra as $col => $val) {
        $sets[]   = "$col = ?";
        $params[] = $val;
    }
    $params[] = $id;
    $db->execute('UPDATE rd_downloads SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

function guessCategory(string $ext, string $fallback): string
{
    global $videoExts, $audioExts, $audioBookExts, $ebookExts;
    if (in_array($ext, $videoExts, true))       return $fallback ?: 'movies';
    if (in_array($ext, $audioBookExts, true))   return 'books';
    if (in_array($ext, $ebookExts, true))       return 'books';
    if (in_array($ext, $audioExts, true))       return 'music';
    return $fallback ?: 'movies';
}

function streamDownload(GuzzleHttp\ClientInterface $http, string $url, string $destPath): bool
{
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $tmp = $destPath . '.rdtmp';
    $fp  = fopen($tmp, 'wb');
    if (!$fp) return false;

    $response = $http->request('GET', $url, [
        'stream'  => true,
        'headers' => ['User-Agent' => 'MediaServer/1.0'],
    ]);

    if ($response->getStatusCode() >= 400) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    $body = $response->getBody();
    while (!$body->eof()) {
        fwrite($fp, $body->read(131072)); // 128 KB chunks
    }
    fclose($fp);

    rename($tmp, $destPath);
    return true;
}

// ── Direct URL mode ───────────────────────────────────────────────────────

if ($directUrl) {
    setStatus($db, $id, 'fetching');
    $filename = basename(parse_url($directUrl, PHP_URL_PATH));
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $cat      = guessCategory($ext, $directCat ?? '');
    $dest     = $libraryPath . '/' . $cat . '/' . $filename;

    $ok = streamDownload($http, $directUrl, $dest);
    if (!$ok) {
        setStatus($db, $id, 'error', ['error' => 'Download failed']);
        exit(1);
    }

    setStatus($db, $id, 'done', ['filename' => $filename, 'progress' => 100]);
    triggerScan($cat);
    exit(0);
}

// ── Torrent mode ──────────────────────────────────────────────────────────

$row = $db->first('SELECT * FROM rd_downloads WHERE id = ?', [$id]);
if (!$row) exit(1);

$category = $row['category'] ?? 'movies';

// Poll until RD has finished downloading the torrent (or error/dead)
$maxWait  = 7200; // 2 hours
$interval = 10;   // seconds between polls
$waited   = 0;

while ($waited < $maxWait) {
    try {
        $info   = $rd->getTorrentInfo($id);
        $rdStat = $info['status'] ?? 'unknown';

        setStatus($db, $id, 'downloading', [
            'rd_status' => $rdStat,
            'progress'  => (float) ($info['progress'] ?? 0),
            'filename'  => $info['filename'] ?? null,
            'size'      => $info['bytes']    ?? null,
        ]);

        if ($rdStat === 'downloaded') break;

        if (in_array($rdStat, ['error', 'virus', 'dead', 'magnet_error'], true)) {
            setStatus($db, $id, 'error', ['error' => 'RD status: ' . $rdStat]);
            exit(1);
        }
    } catch (\Throwable $e) {
        // transient error — keep polling
    }

    sleep($interval);
    $waited += $interval;
}

if ($waited >= $maxWait) {
    setStatus($db, $id, 'error', ['error' => 'Timed out waiting for RD']);
    exit(1);
}

// Torrent is downloaded on RD — unrestrict each link and fetch
$links = $info['links'] ?? [];
if (empty($links)) {
    setStatus($db, $id, 'error', ['error' => 'No download links returned by RD']);
    exit(1);
}

setStatus($db, $id, 'fetching');

$errors = [];
foreach ($links as $link) {
    try {
        $unrestricted = $rd->unrestrictLink($link);
        $dlUrl        = $unrestricted['download'] ?? null;
        $filename     = $unrestricted['filename']  ?? basename(parse_url($link, PHP_URL_PATH));
        if (!$dlUrl) continue;

        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $cat  = guessCategory($ext, $category);
        $dest = $libraryPath . '/' . $cat . '/' . $filename;

        if (!streamDownload($http, $dlUrl, $dest)) {
            $errors[] = $filename;
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors) {
    setStatus($db, $id, 'error', ['error' => 'Some files failed: ' . implode('; ', $errors)]);
    exit(1);
}

setStatus($db, $id, 'done', ['progress' => 100]);

// Trigger a scan for the category
triggerScan($category);
exit(0);

function triggerScan(string $category): void
{
    global $root;
    $php    = PHP_BINARY;
    $script = realpath($root . '/bin/scan.php');
    if ($script) {
        exec(sprintf('%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php), escapeshellarg($script), escapeshellarg($category)
        ));
    }
}
