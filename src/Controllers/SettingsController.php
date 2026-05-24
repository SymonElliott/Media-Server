<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Services\AppLogger;
use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class SettingsController
{
    /**
     * Keys manageable through the UI.
     * All stored in the `settings` DB table — no .env write needed.
     * Settings::getEnv() reads DB-first then falls back to $_ENV, so values
     * set here take effect immediately without a container restart.
     */
    private const ENV_KEYS = [
        'APP_DEBUG',
        'REAL_DEBRID_API_KEY',
        'TMDB_API_KEY',
        'SONARR_URL',
        'SONARR_API_KEY',
        'RADARR_URL',
        'RADARR_API_KEY',
        'LIDARR_URL',
        'LIDARR_API_KEY',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection  $db,
        private readonly Settings    $settings,
        private readonly AppLogger   $log,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        // Build display values: DB-first, falls back to $_ENV (Docker env / .env defaults)
        $env = [];
        foreach (self::ENV_KEYS as $key) {
            $env[$key] = $this->settings->getEnv($key);
        }

        $html = $this->twig->render('settings.html.twig', [
            'settings' => $this->settings->all(),
            'env'      => $env,
            'saved'    => $request->getQueryParams()['saved'] ?? false,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function save(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        // Boolean toggle stored directly
        $this->settings->set('auto_rename', isset($body['auto_rename']) ? '1' : '0');

        // All ENV_KEYS go into the DB — background scripts (scan.php, enrich.php,
        // rd_fetch.php) now use Settings::getEnv() so they pick up DB values too.
        foreach (self::ENV_KEYS as $key) {
            if ($key === 'APP_DEBUG') {
                // Checkbox — absent from POST body when unchecked
                $this->settings->set('APP_DEBUG', isset($body['APP_DEBUG']) ? 'true' : 'false');
            } elseif (array_key_exists($key, $body)) {
                $this->settings->set($key, trim($body[$key]));
            }
        }

        $this->log->info('settings', 'Settings updated by ' . ($_SESSION['user']['username'] ?? 'unknown'));

        $response->getBody()->write(json_encode(['saved' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** GET /system/version — returns current git commit info. */
    public function version(Request $request, Response $response): Response
    {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            return $response->withStatus(403);
        }

        $appRoot = dirname(__DIR__, 2);
        $info    = $this->gitInfo($appRoot);

        $response->getBody()->write(json_encode($info));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /system/git-pull — runs git pull, composer install if deps changed, then clears opcache. Admin only. */
    public function gitPull(Request $request, Response $response): Response
    {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            return $response->withStatus(403);
        }

        $appRoot = dirname(__DIR__, 2);
        $user    = $_SESSION['user']['username'] ?? 'unknown';
        $steps   = [];

        // ── Step 1: git pull ────────────────────────────────────────────────
        [$pullOut, $pullExit] = $this->runCmd(['git', 'pull'], $appRoot);
        $pullSuccess = ($pullExit === 0);
        $steps[] = [
            'label'   => 'git pull',
            'output'  => $pullOut ?: '(no output)',
            'success' => $pullSuccess,
        ];

        $this->log->info('system', sprintf(
            'git pull by %s — %s',
            $user,
            $pullSuccess ? 'success' : "exit {$pullExit}"
        ));

        // ── Step 2: composer install (only if composer.lock changed) ────────
        $composerStep = null;
        if ($pullSuccess && str_contains($pullOut, 'composer.lock')) {
            [$compOut, $compExit] = $this->runCmd(
                ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'],
                $appRoot
            );
            $composerStep = [
                'label'   => 'composer install',
                'output'  => $compOut ?: '(no output)',
                'success' => ($compExit === 0),
            ];
            $steps[] = $composerStep;
            $this->log->info('system', sprintf(
                'composer install — %s',
                $compExit === 0 ? 'success' : "exit {$compExit}"
            ));
        }

        // ── Step 3: opcache reset ───────────────────────────────────────────
        $opcacheCleared = false;
        if ($pullSuccess) {
            $opcacheCleared = function_exists('opcache_reset') && opcache_reset();
            $steps[] = [
                'label'   => 'opcache reset',
                'output'  => $opcacheCleared ? 'Opcode cache cleared.' : 'opcache not active — nothing to clear.',
                'success' => true,
            ];
        }

        // ── Did anything important change? ───────────────────────────────────
        $alreadyUpToDate = str_contains($pullOut, 'Already up to date');
        $needsRebuild    = $pullSuccess && (
            str_contains($pullOut, 'Dockerfile') ||
            str_contains($pullOut, 'docker/') ||
            str_contains($pullOut, 'composer.json')  // composer.json change, not just .lock
        );

        $info = $pullSuccess ? $this->gitInfo($appRoot) : [];

        $response->getBody()->write(json_encode(array_merge([
            'success'          => $pullSuccess,
            'already_up_to_date' => $alreadyUpToDate,
            'needs_rebuild'    => $needsRebuild,
            'steps'            => $steps,
        ], $info)));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /** GET /system/db-stats — row counts for every key table + view. Admin only. */
    public function dbStats(Request $request, Response $response): Response
    {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            return $response->withStatus(403);
        }

        $pdo  = $this->db->pdo();
        $data = [];

        // Raw base + extension tables
        foreach (['media', 'media_shows', 'media_music', 'media_books',
                  'people', 'series_meta', 'album_meta', 'progress', 'users'] as $tbl) {
            try {
                $n = $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
                $data['tables'][$tbl] = (int) $n;
            } catch (\Throwable $e) {
                $data['tables'][$tbl] = 'ERROR: ' . $e->getMessage();
            }
        }

        // Per-type counts from the base table
        try {
            $rows = $pdo->query("SELECT type, COUNT(*) as n FROM media GROUP BY type")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $data['media_by_type'][$r['type']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
            $data['media_by_type'] = 'ERROR: ' . $e->getMessage();
        }

        // v_media view health check
        try {
            $n = $pdo->query("SELECT COUNT(*) FROM v_media")->fetchColumn();
            $data['v_media_count'] = (int) $n;
            $data['v_media_ok']    = true;
        } catch (\Throwable $e) {
            $data['v_media_count'] = null;
            $data['v_media_ok']    = false;
            $data['v_media_error'] = $e->getMessage();
        }

        // Show the stored view SQL so we can see if media_legacy is still in it
        try {
            $row = $pdo->query(
                "SELECT sql FROM sqlite_master WHERE type='view' AND name='v_media'"
            )->fetch(\PDO::FETCH_ASSOC);
            $data['v_media_sql'] = $row['sql'] ?? null;
        } catch (\Throwable $e) {
            $data['v_media_sql'] = 'ERROR: ' . $e->getMessage();
        }

        // SQLite version
        try {
            $data['sqlite_version'] = $pdo->query("SELECT sqlite_version()")->fetchColumn();
        } catch (\Throwable $e) {
            $data['sqlite_version'] = 'unknown';
        }

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /system/db-clear — wipe all media/people/metadata rows. Admin only. */
    public function dbClear(Request $request, Response $response): Response
    {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            return $response->withStatus(403);
        }

        $pdo = $this->db->pdo();

        // Wrap in a transaction so it either fully completes or fully rolls back.
        $pdo->beginTransaction();
        try {
            // Delete all media rows — ON DELETE CASCADE removes extension rows
            // (media_shows, media_music, media_books) automatically.
            $pdo->exec('DELETE FROM media');

            // Clear derived/enrichment tables
            $pdo->exec('DELETE FROM people');
            $pdo->exec('DELETE FROM series_meta');
            $pdo->exec('DELETE FROM album_meta');

            // Clear watch progress (media it referenced is gone)
            $pdo->exec('DELETE FROM progress');

            // Reset autoincrement counters so IDs start from 1 again
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN
                ('media','people','series_meta','album_meta','progress')");

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->log->error('system', 'db-clear failed: ' . $e->getMessage());
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $this->log->info('system', 'Database cleared by ' . ($_SESSION['user']['username'] ?? 'unknown'));
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function gitInfo(string $dir): array
    {
        return [
            'commit'  => $this->runCmd(['git', 'rev-parse', '--short', 'HEAD'], $dir)[0],
            'branch'  => $this->runCmd(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $dir)[0],
            'message' => $this->runCmd(['git', 'log', '-1', '--pretty=%s'], $dir)[0],
            'date'    => $this->runCmd(['git', 'log', '-1', '--pretty=%ci'], $dir)[0],
        ];
    }

    /**
     * Run a command in $cwd, merging stdout + stderr.
     * Returns [string $output, int $exitCode].
     */
    private function runCmd(array $cmd, string $cwd): array
    {
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );

        if (!is_resource($proc)) {
            return ['Failed to start process: ' . implode(' ', $cmd), 1];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $out = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
        return [$out, $exit];
    }
}
