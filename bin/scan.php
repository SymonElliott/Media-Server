#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

$stateFile   = $root . '/storage/scan.json';
$libraryPath = $_ENV['LIBRARY_PATH'] ?? '/library';
$coversDir   = $root . '/public/covers';

$validTypes = ['movies', 'shows', 'music', 'audiobooks', 'books'];
$filterType = isset($argv[1]) && in_array($argv[1], $validTypes, true) ? $argv[1] : null;

$db        = new App\Database\Connection($root . '/storage/db/media.sqlite');
$http      = new GuzzleHttp\Client(['timeout' => 15, 'http_errors' => false]);
$scanner   = new App\Services\LibraryScanner($db, $libraryPath, $stateFile);
$metadata  = new App\Services\Metadata\MetadataService(
    $db,
    new App\Services\Metadata\TmdbProvider($http, $_ENV['TMDB_API_KEY'] ?? ''),
    new App\Services\Metadata\MusicBrainzProvider($http, $_ENV['MUSICBRAINZ_USER_AGENT'] ?? 'MediaServer/1.0'),
    new App\Services\Metadata\OpenLibraryProvider($http),
    $http,
    $coversDir
);

// Pre-count files per type so the UI can show per-tile progress bars
$typeTotals = $scanner->countFiles($filterType);   // also caches in $scanner for writeState()
$fileTotal  = array_sum($typeTotals);

file_put_contents($stateFile, json_encode([
    'running'      => true,
    'phase'        => 'scanning',
    'current_type' => null,
    'total'        => $fileTotal,
    'type_totals'  => $typeTotals,
    'processed'    => 0,
    'added'        => 0,
    'updated'      => 0,
    'skipped'      => 0,
]), LOCK_EX);

// Phase 1: index files
$stats = $scanner->scan($filterType);

// Count items that still need metadata enrichment for phase-2 progress
$metaQuery  = 'SELECT COUNT(*) as n FROM media WHERE metadata_fetched_at IS NULL';
$metaParams = [];
if ($filterType) {
    $metaQuery  .= ' AND type = ?';
    $metaParams[] = $filterType;
}
$metaTotal = (int) ($db->first($metaQuery, $metaParams)['n'] ?? 0);

file_put_contents($stateFile, json_encode([
    'running'      => true,
    'phase'        => 'metadata',
    'current_type' => null,
    'total'        => $metaTotal,
    'processed'    => 0,
    ...$stats,
]), LOCK_EX);

// Phase 2: enrich metadata
$done     = 0;
$progress = function (string $type, int|string $itemKey, ?string $itemName = null) use ($stateFile, $stats, $metaTotal, &$done) {
    file_put_contents($stateFile, json_encode([
        'running'           => true,
        'phase'             => 'metadata',
        'current_type'      => $type,
        'current_item_id'   => is_int($itemKey) ? $itemKey : null,
        'current_group'     => is_string($itemKey) ? $itemKey : null,
        'current_item_name' => $itemName,
        'total'             => $metaTotal,
        'processed'         => $done,
        ...$stats,
    ]), LOCK_EX);
    $done++;
};

if ($filterType) {
    $metadata->enrichType($filterType, $progress);
} else {
    $metadata->enrichAll($progress);
}

file_put_contents($stateFile, json_encode([
    'running'         => false,
    'current_type'    => null,
    'current_item_id' => null,
    'current_group'   => null,
    'finished_at'     => date('c'),
    ...$stats,
]), LOCK_EX);
