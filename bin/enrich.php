#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';
require $root . '/src/Bootstrap/env.php';

$type      = $argv[1] ?? null;
$stateFile = $root . '/storage/scan.json';
$coversDir = $root . '/public/covers';

$db       = new App\Database\Connection($root . '/storage/db/media.sqlite');
$settings = new App\Services\Settings($db);
$http     = new GuzzleHttp\Client(['timeout' => 15, 'http_errors' => false]);

$metadata = new App\Services\Metadata\MetadataService(
    $db,
    new App\Services\Metadata\TmdbProvider($http, $settings->getEnv('TMDB_API_KEY')),
    new App\Services\Metadata\MusicBrainzProvider($http, 'MediaServer/1.0'),
    new App\Services\Metadata\OpenLibraryProvider($http),
    new App\Services\Metadata\AudnexusProvider($http),
    $http,
    $coversDir
);

$countSql  = $type
    ? 'SELECT COUNT(*) as n FROM media WHERE metadata_fetched_at IS NULL AND type = ?'
    : 'SELECT COUNT(*) as n FROM media WHERE metadata_fetched_at IS NULL';
$total = (int) ($db->first($countSql, $type ? [$type] : [])['n'] ?? 0);

file_put_contents($stateFile, json_encode([
    'running'      => true,
    'phase'        => 'metadata',
    'current_type' => $type,
    'total'        => $total,
    'processed'    => 0,
    'added'        => 0,
    'updated'      => 0,
    'skipped'      => 0,
]), LOCK_EX);

$done     = 0;
$callback = function (string $t, int|string $itemKey, ?string $itemName = null) use ($stateFile, $total, &$done) {
    file_put_contents($stateFile, json_encode([
        'running'           => true,
        'phase'             => 'metadata',
        'current_type'      => $t,
        'current_item_id'   => is_int($itemKey) ? $itemKey : null,
        'current_group'     => is_string($itemKey) ? $itemKey : null,
        'current_item_name' => $itemName,
        'total'             => $total,
        'processed'         => $done,
        'added'             => 0,
        'updated'           => 0,
        'skipped'           => 0,
    ]), LOCK_EX);
    $done++;
};

if ($type) {
    $metadata->enrichType($type, $callback);
} else {
    $metadata->enrichAll($callback);
}

file_put_contents($stateFile, json_encode([
    'running'           => false,
    'current_type'      => null,
    'current_item_id'   => null,
    'current_group'     => null,
    'current_item_name' => null,
    'finished_at'       => date('c'),
    'added'             => 0,
    'updated'           => 0,
    'skipped'           => 0,
]), LOCK_EX);
