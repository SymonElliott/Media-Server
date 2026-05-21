<?php

declare(strict_types=1);

namespace App\Controllers;

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
        'MEDIA_PATH',
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
        private readonly Settings    $settings,
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

        $response->getBody()->write(json_encode(['saved' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
