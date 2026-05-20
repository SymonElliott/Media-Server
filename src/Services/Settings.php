<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

class Settings
{
    private array $cache  = [];
    private bool  $loaded = false;

    public function __construct(private readonly Connection $db) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    /** DB-first; falls back to $_ENV so existing .env values work before the UI is ever saved. */
    public function getEnv(string $key, string $default = ''): string
    {
        $this->load();
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        return (string) ($_ENV[$key] ?? $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default ? '1' : '0');
    }

    public function set(string $key, mixed $value): void
    {
        $v = (string) $value;
        $this->db->execute(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $v]
        );
        $this->cache[$key] = $v;
    }

    public function all(): array
    {
        $this->load();
        return $this->cache;
    }

    private function load(): void
    {
        if ($this->loaded) return;
        foreach ($this->db->query('SELECT key, value FROM settings') as $row) {
            $this->cache[$row['key']] = $row['value'];
        }
        $this->loaded = true;
    }
}
