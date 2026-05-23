<?php

declare(strict_types=1);

/**
 * Lightweight .env loader.
 *
 * Parses the project-root .env file and populates $_ENV / putenv() for any
 * key that isn't already set in the environment.  Already-set values (e.g.
 * real Docker environment variables) always win.
 *
 * Supports:
 *   KEY=value          bare value
 *   KEY="value"        double-quoted (escape sequences not expanded)
 *   KEY='value'        single-quoted (literal)
 *   # comment lines    ignored
 *   inline # comments  stripped outside quotes
 */
(static function (): void {
    $file = dirname(__DIR__, 2) . '/.env';
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key   = rtrim(substr($line, 0, $eq));
        $raw   = ltrim(substr($line, $eq + 1));

        // Strip inline comments outside of quotes
        if ($raw !== '' && $raw[0] === '"') {
            $end   = strrpos($raw, '"');
            $value = $end > 0 ? substr($raw, 1, $end - 1) : substr($raw, 1);
        } elseif ($raw !== '' && $raw[0] === "'") {
            $end   = strrpos($raw, "'");
            $value = $end > 0 ? substr($raw, 1, $end - 1) : substr($raw, 1);
        } else {
            // Strip trailing inline comment  (e.g. VALUE=foo  # note)
            $hash  = strpos($raw, ' #');
            $value = $hash !== false ? rtrim(substr($raw, 0, $hash)) : $raw;
        }

        // Don't overwrite values already injected by the OS / Docker environment.
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();
