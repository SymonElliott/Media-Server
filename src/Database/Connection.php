<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

class Connection
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $results = $this->query($sql, $params);
        return $results[0] ?? null;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS people (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                name                TEXT NOT NULL,
                slug                TEXT NOT NULL UNIQUE,
                role                TEXT NOT NULL DEFAULT 'author',
                image               TEXT,
                bio                 TEXT,
                external_id         TEXT,
                external_source     TEXT,
                metadata_fetched_at DATETIME,
                indexed_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_people_role ON people(role);
            CREATE INDEX IF NOT EXISTS idx_people_slug ON people(slug);

            CREATE TABLE IF NOT EXISTS series_meta (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                series              TEXT NOT NULL,
                author              TEXT,
                title               TEXT,
                description         TEXT,
                poster              TEXT,
                author_image        TEXT,
                author_asin         TEXT,
                year                INTEGER,
                external_id         TEXT,
                external_source     TEXT,
                metadata            TEXT DEFAULT '{}',
                metadata_fetched_at DATETIME,
                UNIQUE(series, author)
            );

            CREATE TABLE IF NOT EXISTS media (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                type                TEXT NOT NULL,
                path                TEXT NOT NULL UNIQUE,
                filename            TEXT NOT NULL,
                extension           TEXT NOT NULL,
                size                INTEGER DEFAULT 0,
                title               TEXT,
                author              TEXT,
                series              TEXT,
                book_name           TEXT,
                book_version        TEXT,
                show_name           TEXT,
                season              INTEGER,
                episode             INTEGER,
                year                INTEGER,
                description         TEXT,
                poster              TEXT,
                external_id         TEXT,
                external_source     TEXT,
                metadata            TEXT DEFAULT '{}',
                metadata_fetched_at DATETIME,
                indexed_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_media_type   ON media(type);
            CREATE INDEX IF NOT EXISTS idx_media_show   ON media(show_name);
            CREATE INDEX IF NOT EXISTS idx_media_author ON media(author);
        SQL);

        // Add columns that existing DBs won't have yet (must run before index creation below)
        $existing = array_column(
            $this->pdo->query('PRAGMA table_info(media)')->fetchAll(),
            'name'
        );
        foreach ([
            'description'         => 'TEXT',
            'poster'              => 'TEXT',
            'still'               => 'TEXT',
            'external_id'         => 'TEXT',
            'external_source'     => 'TEXT',
            'metadata_fetched_at' => 'DATETIME',
            'book_name'           => 'TEXT',
            'book_version'        => 'TEXT',
            'duration'            => 'INTEGER',
            'series_order'        => 'REAL',
        ] as $col => $type) {
            if (!in_array($col, $existing, true)) {
                $this->pdo->exec("ALTER TABLE media ADD COLUMN $col $type");
            }
        }

        // Add columns that existing series_meta tables won't have yet
        $existingSm = array_column(
            $this->pdo->query('PRAGMA table_info(series_meta)')->fetchAll(),
            'name'
        );
        foreach (['author_image' => 'TEXT', 'author_asin' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $existingSm, true)) {
                $this->pdo->exec("ALTER TABLE series_meta ADD COLUMN $col $type");
            }
        }

        // Indexes on columns that may have been added above
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_media_book ON media(book_name)'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_series_meta ON series_meta(series, author)'
        );
    }
}
