<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class SettingsController
{
    private string $envPath;

    /** Keys in .env that are editable through the UI. */
    private const ENV_KEYS = [
        'MEDIA_PATH',
        'APP_DEBUG',
        'REAL_DEBRID_API_KEY',
        'TMDB_API_KEY',
        'MUSICBRAINZ_USER_AGENT',
        'SONARR_URL',
        'SONARR_API_KEY',
        'RADARR_URL',
        'RADARR_API_KEY',
        'LIDARR_URL',
        'LIDARR_API_KEY',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly Settings    $settings,
        string $projectRoot
    ) {
        $this->envPath = rtrim($projectRoot, '/') . '/.env';
    }

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('settings.html.twig', [
            'settings' => $this->settings->all(),
            'env'      => $this->readEnv(),
            'saved'    => $request->getQueryParams()['saved'] ?? false,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function save(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        // DB-backed boolean toggles
        $this->settings->set('auto_rename', isset($body['auto_rename']) ? '1' : '0');

        // .env keys — only update keys we explicitly manage
        $envUpdates = [];
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $envUpdates[$key] = trim($body[$key]);
            }
        }
        // APP_DEBUG is a checkbox, not a text field
        $envUpdates['APP_DEBUG'] = isset($body['APP_DEBUG']) ? 'true' : 'false';

        if ($envUpdates) {
            // Write to DB for immediate effect (no restart needed)
            foreach ($envUpdates as $key => $val) {
                $this->settings->set($key, $val);
            }
            // Also write to .env for persistence across restarts
            $this->writeEnv($envUpdates);
        }

        $response->getBody()->write(json_encode(['saved' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── .env helpers ──────────────────────────────────────────────────────

    private function readEnv(): array
    {
        $values = [];
        if (!file_exists($this->envPath)) return $values;

        foreach (file($this->envPath, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $values[trim($key)] = trim($val);
        }
        return $values;
    }

    private function writeEnv(array $updates): void
    {
        if (!file_exists($this->envPath)) return;

        $lines   = file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
        $written = [];
        $out     = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                $out[] = $line;
                continue;
            }
            [$key] = explode('=', $trimmed, 2);
            $key   = trim($key);
            if (array_key_exists($key, $updates)) {
                $out[]      = $key . '=' . $updates[$key];
                $written[]  = $key;
            } else {
                $out[] = $line;
            }
        }

        // Append any keys that weren't in the file yet
        foreach ($updates as $key => $val) {
            if (!in_array($key, $written, true)) {
                $out[] = $key . '=' . $val;
            }
        }

        file_put_contents($this->envPath, implode("\n", $out) . "\n");
    }
}
