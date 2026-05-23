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

        // busy_timeout must come FIRST — it arms the retry handler before any
        // subsequent PRAGMA or query that might encounter a locked database.
        // WAL mode: readers don't block the writer and vice-versa.
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        try {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
            // WAL switch requires a brief exclusive lock; if a scan is mid-write
            // and the 10 s timeout is still exceeded, skip — the database already
            // has a journal mode set and will continue to work correctly.
        }

        // Enable foreign key constraints
        $this->pdo->exec('PRAGMA foreign_keys = ON');

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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    UNIQUE NOT NULL,
                password_hash TEXT    NOT NULL,
                role          TEXT    NOT NULL DEFAULT 'user',
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS progress (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                media_id     INTEGER NOT NULL,
                position     REAL    NOT NULL DEFAULT 0,
                duration     REAL    NOT NULL DEFAULT 0,
                position_cfi TEXT,
                completed    INTEGER NOT NULL DEFAULT 0,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, media_id)
            );
            CREATE INDEX IF NOT EXISTS idx_progress_user ON progress(user_id, updated_at);

            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS rd_downloads (
                id          TEXT PRIMARY KEY,
                type        TEXT NOT NULL DEFAULT 'torrent',
                status      TEXT NOT NULL DEFAULT 'queued',
                rd_status   TEXT,
                filename    TEXT,
                category    TEXT,
                size        INTEGER,
                progress    REAL DEFAULT 0,
                error       TEXT,
                added_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

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

            CREATE TABLE IF NOT EXISTS album_meta (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                album               TEXT NOT NULL,
                artist              TEXT,
                title               TEXT,
                description         TEXT,
                poster              TEXT,
                year                INTEGER,
                external_id         TEXT,
                external_source     TEXT,
                metadata            TEXT DEFAULT '{}',
                metadata_fetched_at DATETIME,
                UNIQUE(album, artist)
            );

            CREATE INDEX IF NOT EXISTS idx_album_meta ON album_meta(album, artist);

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
            CREATE INDEX IF NOT EXISTS idx_media_series ON media(series);
            CREATE INDEX IF NOT EXISTS idx_media_title  ON media(title);
            CREATE INDEX IF NOT EXISTS idx_media_year   ON media(year);
            CREATE INDEX IF NOT EXISTS idx_media_book_name ON media(book_name);
            CREATE INDEX IF NOT EXISTS idx_media_season ON media(season);
            CREATE INDEX IF NOT EXISTS idx_media_episode ON media(episode);
            CREATE INDEX IF NOT EXISTS idx_media_indexed_at ON media(indexed_at);
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
            'chapters'            => 'TEXT',
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
