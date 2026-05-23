#!/usr/bin/env php
<?php

declare(strict_types=1);

// The Docker ini file sets max_execution_time = 300 which also applies to CLI PHP.
// Override it here so the scan is never killed mid-run regardless of library size.
set_time_limit(0);
ignore_user_abort(true);

$root    = dirname(__DIR__);
$logFile = $root . '/storage/scan.log';

// ── Pre-autoload boot marker ──────────────────────────────────────────────────
// Written before anything else so it's visible even if autoload crashes.
file_put_contents(
    $logFile,
    date('[H:i:s]') . ' [BOOT] scan.php launched — PID ' . getmypid() . ', PHP ' . PHP_VERSION . "\n"
);

require $root . '/vendor/autoload.php';

file_put_contents($logFile, date('[H:i:s]') . " [BOOT] autoload OK\n", FILE_APPEND);

$stateFile = $root . '/storage/scan.json';
$coversDir = $root . '/public/covers';

// ── Crash-safety shutdown function ───────────────────────────────────────────
// Runs on every exit path: normal completion, uncaught exception, fatal error,
// or SIGTERM/SIGKILL.  If the state file still says running=true when we get
// here, something went wrong — mark it finished so the UI isn't stuck.
register_shutdown_function(static function () use ($stateFile): void {
    $raw   = @file_get_contents($stateFile);
    $state = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];
    if ($state['running'] ?? false) {
        file_put_contents($stateFile, json_encode(array_merge($state, [
            'running'     => false,
            'finished_at' => date('c'),
            'error'       => 'Scan process exited unexpectedly',
        ])), LOCK_EX);
    }
});

$validTypes  = ['movies', 'shows', 'music', 'books'];
$filterType  = isset($argv[1]) && in_array($argv[1], $validTypes, true) ? $argv[1] : null;
$filterGroup = isset($argv[2]) && $argv[2] !== '' ? $argv[2] : null;

$scanDesc = match(true) {
    $filterGroup !== null => ucfirst($filterType ?? '') . ' › ' . $filterGroup,
    $filterType !== null  => ucfirst($filterType),
    default               => 'full library',
};

// ── Logger ────────────────────────────────────────────────────────────────────
$scanLogger = new App\Services\ScanLogger($logFile);
$appLogger  = new App\Services\AppLogger($root . '/storage/app.log');

$t0      = microtime(true);
$elapsed = fn() => sprintf('+%.2fs', microtime(true) - $t0);

// Overwrite (not append) the log file — atomically replaces the [BOOT]
// diagnostic markers with "Scan started".
file_put_contents($logFile, date('[H:i:s]') . " Scan started ($scanDesc)\n", LOCK_EX);
$appLogger->info('scan', "Scan started ($scanDesc)");

// ── Service wiring ────────────────────────────────────────────────────────────
$scanLogger->info('PID ' . getmypid() . '  PHP ' . PHP_VERSION);
$scanLogger->info('');

$scanLogger->info('Connecting to database…');
$db = new App\Database\Connection($root . '/storage/db/media.sqlite');
$scanLogger->info('Database OK  ' . $elapsed());

$scanLogger->info('Loading settings…');
$settings    = new App\Services\Settings($db);
$libraryPath = dirname(__DIR__) . '/library';
$tmdbKey     = $settings->getEnv('TMDB_API_KEY') ? 'set' : 'NOT SET';
$scanLogger->info("Settings OK  {$elapsed()}  TMDB={$tmdbKey}  library={$libraryPath}");

// ── Library path guard ────────────────────────────────────────────────────────
if (!is_dir($libraryPath)) {
    $scanLogger->info('');
    $scanLogger->info("ERROR: Library directory not found: {$libraryPath}");
    $scanLogger->info('Check that your media volume is mounted.');
    $appLogger->error('scan', "Scan aborted — library directory not found: {$libraryPath}");
    file_put_contents($stateFile, json_encode([
        'running'     => false,
        'error'       => "Library directory not found: {$libraryPath}",
        'finished_at' => date('c'),
    ]), LOCK_EX);
    exit(1);
}

$scanLogger->info('Building HTTP client…');
$http = new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 8, 'http_errors' => false]);
$scanLogger->info('HTTP client OK  ' . $elapsed());

$scanLogger->info('Wiring metadata services…');
$tmdb     = new App\Services\Metadata\TmdbProvider($http, $settings->getEnv('TMDB_API_KEY'));
$openLib  = new App\Services\Metadata\OpenLibraryProvider($http);
$audnexus = new App\Services\Metadata\AudnexusProvider($http);
$scanner  = new App\Services\LibraryScanner($db, $libraryPath, $stateFile);
$metadata = new App\Services\Metadata\MetadataService(
    $db,
    $tmdb,
    new App\Services\Metadata\MusicBrainzProvider($http, 'MediaServer/1.0'),
    $openLib,
    $audnexus,
    $http,
    $coversDir
);
$scanner->setLogger(fn(string $msg) => $scanLogger->info($msg));
$metadata->setLogger(fn(string $msg) => $scanLogger->info($msg));
$people = new App\Services\PeopleService($db, $tmdb, $audnexus, $openLib, $http, $coversDir);
$scanLogger->info('Services ready  ' . $elapsed());

// Use existing DB row counts as a fast estimate for the progress total.
$typeRows   = $db->query('SELECT type, COUNT(*) as n FROM media GROUP BY type');
$typeTotals = array_column($typeRows, 'n', 'type');
$fileTotal  = array_sum($typeTotals);
$scanner->setTypeTotals($typeTotals);

file_put_contents($stateFile, json_encode([
    'running'      => true,
    'pid'          => getmypid(),
    'phase'        => 'scanning',
    'current_type' => null,
    'total'        => $fileTotal,
    'type_totals'  => $typeTotals,
    'processed'    => 0,
    'added'        => 0,
    'updated'      => 0,
    'skipped'      => 0,
]), LOCK_EX);

// ── Phases 1-3 (all wrapped — any uncaught throw is caught here) ──────────────
$stats     = ['added' => 0, 'updated' => 0, 'skipped' => 0];
$scanStart = microtime(true);

try {

    // ── Phase 1: index files ──────────────────────────────────────────────────
    $scanLogger->info('');
    $scanLogger->info('── Phase 1: Indexing files ──────────────────────────────');
    $stats         = $scanner->scan($filterType, $filterGroup);
    $phase1Summary = sprintf(
        'Phase 1 complete in %.1fs  ·  %d added  ·  %d updated  ·  %d skipped',
        microtime(true) - $scanStart,
        $stats['added'],
        $stats['updated'],
        $stats['skipped']
    );
    $scanLogger->info($phase1Summary);
    $appLogger->info('scan', $phase1Summary);

    // Count items that still need metadata enrichment for phase-2 progress
    $metaQuery  = 'SELECT COUNT(*) as n FROM media WHERE metadata_fetched_at IS NULL';
    $metaParams = [];
    if ($filterType) {
        $metaQuery    .= ' AND type = ?';
        $metaParams[]  = $filterType;
    }
    if ($filterGroup) {
        $groupCol     = ($filterType === 'shows') ? 'show_name' : 'author';
        $metaQuery   .= " AND $groupCol = ?";
        $metaParams[] = $filterGroup;
    }
    $metaTotal = (int) ($db->first($metaQuery, $metaParams)['n'] ?? 0);

    file_put_contents($stateFile, json_encode([
        'running'      => true,
        'pid'          => getmypid(),
        'phase'        => 'metadata',
        'current_type' => null,
        'total'        => $metaTotal,
        'processed'    => 0,
        ...$stats,
    ]), LOCK_EX);

    // ── Phase 2: enrich metadata ──────────────────────────────────────────────
    $scanLogger->info('');
    $scanLogger->info(sprintf('── Phase 2: Metadata enrichment  ·  %d items pending ──────', $metaTotal));
    $metaStart = microtime(true);
    $done      = 0;
    $progress  = function (string $type, int|string $itemKey, ?string $itemName = null)
        use ($stateFile, $stats, $metaTotal, &$done): void
    {
        file_put_contents($stateFile, json_encode([
            'running'           => true,
            'pid'               => getmypid(),
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
        $metadata->enrichType($filterType, $progress, $filterGroup);
    } else {
        $metadata->enrichAll($progress);
    }

    if ($metaTotal > 0) {
        $scanLogger->info(sprintf('Phase 2 complete in %.1fs', microtime(true) - $metaStart));
    }

    // ── Phase 3: sync and enrich people ──────────────────────────────────────
    $scanLogger->info('');
    $scanLogger->info('── Phase 3: Syncing people ──────────────────────────────');
    file_put_contents($stateFile, json_encode([
        'running'      => true,
        'pid'          => getmypid(),
        'phase'        => 'people',
        'current_type' => null,
        ...$stats,
    ]), LOCK_EX);

    $people->syncFromMedia();
    $people->enrichAll();

} catch (\Throwable $e) {
    $msg = 'Scan failed: ' . $e->getMessage();
    $scanLogger->info('');
    $scanLogger->info('FATAL: ' . $e->getMessage());
    $scanLogger->info('  at ' . $e->getFile() . ':' . $e->getLine());
    $appLogger->error('scan', $msg);
    // Shutdown function will write running=false; re-throw so PHP sets non-zero exit.
    throw $e;
}

// ── Normal completion ─────────────────────────────────────────────────────────
$elapsed2 = microtime(true) - $scanStart;
$scanLogger->info('');
$scanLogger->info(sprintf('Scan complete in %.1fs', $elapsed2));
$appLogger->info('scan', sprintf(
    'Scan complete in %.1fs — %d added, %d updated, %d skipped',
    $elapsed2,
    $stats['added'],
    $stats['updated'],
    $stats['skipped']
));

// Write finished state — shutdown function sees running=false and is a no-op.
file_put_contents($stateFile, json_encode([
    'running'         => false,
    'current_type'    => null,
    'current_item_id' => null,
    'current_group'   => null,
    'finished_at'     => date('c'),
    ...$stats,
]), LOCK_EX);
