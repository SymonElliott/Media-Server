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

        // busy_timeout first — arms the retry handler before any subsequent
        // PRAGMA or query that might encounter a locked database.
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        try {
            // WAL mode: readers don't block the writer and vice-versa.
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
            // WAL switch needs a brief exclusive lock; skip if another process
            // holds it — the DB already has a journal mode and will work fine.
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->createTables();
        $this->createView();
    }

    // ── Public query helpers ──────────────────────────────────────────────────

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

    // ── Schema ────────────────────────────────────────────────────────────────

    private function createTables(): void
    {
        $this->pdo->exec(<<<'SQL'
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
            CREATE INDEX IF NOT EXISTS idx_series_meta ON series_meta(series, author);

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
        SQL);

        // Base media table — common fields only
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS media (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                type                TEXT NOT NULL,
                path                TEXT NOT NULL UNIQUE,
                filename            TEXT NOT NULL,
                extension           TEXT NOT NULL,
                size                INTEGER DEFAULT 0,
                title               TEXT,
                year                INTEGER,
                description         TEXT,
                poster              TEXT,
                external_id         TEXT,
                external_source     TEXT,
                metadata            TEXT DEFAULT '{}',
                metadata_fetched_at DATETIME,
                indexed_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
                duration            INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_media_type       ON media(type);
            CREATE INDEX IF NOT EXISTS idx_media_title      ON media(title);
            CREATE INDEX IF NOT EXISTS idx_media_year       ON media(year);
            CREATE INDEX IF NOT EXISTS idx_media_indexed_at ON media(indexed_at);
        SQL);

        // Type-specific extension tables — one-to-one with media, ON DELETE CASCADE
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS media_movies (
                media_id    INTEGER PRIMARY KEY REFERENCES media(id) ON DELETE CASCADE,
                director    TEXT,
                collection  TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_media_movies_collection ON media_movies(collection);

            CREATE TABLE IF NOT EXISTS media_shows (
                media_id  INTEGER PRIMARY KEY REFERENCES media(id) ON DELETE CASCADE,
                show_name TEXT,
                season    INTEGER,
                episode   INTEGER,
                still     TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_media_shows_show   ON media_shows(show_name);
            CREATE INDEX IF NOT EXISTS idx_media_shows_season ON media_shows(season, episode);

            CREATE TABLE IF NOT EXISTS media_music (
                media_id    INTEGER PRIMARY KEY REFERENCES media(id) ON DELETE CASCADE,
                artist      TEXT,
                album       TEXT,
                track_order REAL
            );
            CREATE INDEX IF NOT EXISTS idx_media_music_artist ON media_music(artist);
            CREATE INDEX IF NOT EXISTS idx_media_music_album  ON media_music(artist, album);

            CREATE TABLE IF NOT EXISTS media_books (
                media_id     INTEGER PRIMARY KEY REFERENCES media(id) ON DELETE CASCADE,
                book_name    TEXT,
                author       TEXT,
                series       TEXT,
                series_order REAL,
                book_version TEXT,
                chapters     TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_media_books_author    ON media_books(author);
            CREATE INDEX IF NOT EXISTS idx_media_books_book_name ON media_books(book_name);
            CREATE INDEX IF NOT EXISTS idx_media_books_series    ON media_books(series);
        SQL);
    }

    // ── v_media view ──────────────────────────────────────────────────────────
    //
    // Created once with IF NOT EXISTS — never dropped at runtime.
    // If you clear the database, the view is gone too and will be recreated
    // correctly on the next Connection boot.

    private function createView(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE VIEW IF NOT EXISTS v_media AS
            SELECT
                m.id,
                m.type,
                m.path,
                m.filename,
                m.extension,
                m.size,
                m.title,
                m.year,
                m.description,
                m.poster,
                m.external_id,
                m.external_source,
                m.metadata,
                m.metadata_fetched_at,
                m.indexed_at,
                m.duration,
                -- Movies
                mm.director,
                mm.collection,
                -- Shows
                ms.show_name,
                ms.season,
                ms.episode,
                ms.still,
                -- Books / audiobooks
                mb.book_name,
                mb.book_version,
                mb.chapters,
                -- Polymorphic aliases
                CASE m.type
                    WHEN 'music'  THEN mu.artist
                    WHEN 'movies' THEN mm.director
                    ELSE mb.author
                END AS author,
                CASE m.type
                    WHEN 'music'  THEN mu.album
                    WHEN 'movies' THEN mm.collection
                    ELSE mb.series
                END AS series,
                CASE m.type
                    WHEN 'music' THEN mu.track_order
                    ELSE mb.series_order
                END AS series_order
            FROM media m
            LEFT JOIN media_movies mm ON mm.media_id = m.id
            LEFT JOIN media_shows  ms ON ms.media_id = m.id
            LEFT JOIN media_music  mu ON mu.media_id = m.id
            LEFT JOIN media_books  mb ON mb.media_id = m.id
        SQL);
    }
}
