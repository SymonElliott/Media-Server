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
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        try {
            // WAL mode: readers don't block the writer and vice-versa.
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
            // WAL switch requires a brief exclusive lock; skip if another process
            // holds it — the DB already has a journal mode and will work correctly.
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->createTables();
        $this->runMigrations();
        $this->recreateView();
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

    // ── Schema creation (idempotent DDL) ─────────────────────────────────────

    private function createTables(): void
    {
        // ── Non-media tables ──────────────────────────────────────────────────
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

        // ── Base media table (common fields only) ─────────────────────────────
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

        // ── Type-specific extension tables (one-to-one with media) ────────────
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

    // ── Versioned migrations ──────────────────────────────────────────────────
    //
    // Each migration runs exactly once, gated by __schema_version in settings.
    // New schema changes go into the next version block — never edit existing ones.

    private function runMigrations(): void
    {
        $version = (int) ($this->first(
            "SELECT value FROM settings WHERE key = '__schema_version'"
        )['value'] ?? 0);

        if ($version < 1) {
            $this->migrate_v1();
            $this->pdo->exec(
                "INSERT OR REPLACE INTO settings (key, value) VALUES ('__schema_version', '1')"
            );
        }

        // if ($version < 2) { $this->migrate_v2(); ... }
    }

    /**
     * Migration v1 — applied once to any DB that existed before this schema version.
     *
     * 1. Add columns that were introduced in earlier point releases but not
     *    present in very old DBs (idempotent ALTER TABLE).
     * 2. If the DB is still on the old flat schema (show_name on media),
     *    migrate data to the extension tables and recreate media without
     *    the type-specific columns.
     * 3. Backfill media_movies for any movies already in the DB.
     */
    private function migrate_v1(): void
    {
        // ── Step 1: add any legacy columns that may be missing ────────────────
        $mediaColumns = array_column(
            $this->pdo->query('PRAGMA table_info(media)')->fetchAll(), 'name'
        );
        if (!in_array('duration', $mediaColumns, true)) {
            $this->pdo->exec('ALTER TABLE media ADD COLUMN duration INTEGER');
        }

        $smColumns = array_column(
            $this->pdo->query('PRAGMA table_info(series_meta)')->fetchAll(), 'name'
        );
        foreach (['author_image' => 'TEXT', 'author_asin' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $smColumns, true)) {
                $this->pdo->exec("ALTER TABLE series_meta ADD COLUMN $col $type");
            }
        }

        // ── Step 2: migrate from old flat schema if needed ────────────────────
        $mediaColumns = array_column(
            $this->pdo->query('PRAGMA table_info(media)')->fetchAll(), 'name'
        );
        if (in_array('show_name', $mediaColumns, true)) {
            $this->migrateFromFlatSchema();
        }

        // ── Step 3: backfill media_movies for any existing movies ─────────────
        $this->pdo->exec(
            "INSERT OR IGNORE INTO media_movies (media_id)
             SELECT id FROM media WHERE type = 'movies'"
        );

        // ── Step 4: force v_media to be rebuilt with the v1 definition ────────
        // Drops any stale view (old flat schema, media_legacy reference, or missing
        // media_movies JOIN).  recreateView() runs immediately after and recreates
        // it with IF NOT EXISTS, so this drop is the only place v_media is ever
        // removed — eliminating the race window of the previous DROP+CREATE approach.
        $this->pdo->exec('DROP VIEW IF EXISTS v_media');
    }

    /**
     * One-time: populate extension tables from the old flat media table,
     * then recreate media with only the common base columns.
     *
     * Gated by the presence of 'show_name' on media — will never run twice.
     */
    private function migrateFromFlatSchema(): void
    {
        // Populate extension tables. INSERT OR IGNORE so a partial previous run
        // is safe to retry.
        $this->pdo->exec(<<<'SQL'
            INSERT OR IGNORE INTO media_shows (media_id, show_name, season, episode, still)
            SELECT id, show_name, season, episode, still
            FROM media WHERE type = 'shows';

            INSERT OR IGNORE INTO media_music (media_id, artist, album, track_order)
            SELECT id, author, series, series_order
            FROM media WHERE type = 'music';

            INSERT OR IGNORE INTO media_books (media_id, book_name, author, series, series_order, book_version, chapters)
            SELECT id, book_name, author, series, series_order, book_version, chapters
            FROM media WHERE type IN ('books', 'audiobooks');
        SQL);

        // Recreate media without type-specific columns.
        // Drop the view inside the transaction so SQLite 3.26.0+ cannot silently
        // rewrite it to reference media_legacy after the RENAME.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DROP VIEW IF EXISTS v_media');
            $this->pdo->exec('ALTER TABLE media RENAME TO media_legacy');
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE media (
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
                )
            SQL);
            $this->pdo->exec(<<<'SQL'
                INSERT INTO media (id, type, path, filename, extension, size, title,
                                   year, description, poster, external_id, external_source,
                                   metadata, metadata_fetched_at, indexed_at, duration)
                SELECT              id, type, path, filename, extension, size, title,
                                   year, description, poster, external_id, external_source,
                                   metadata, metadata_fetched_at, indexed_at, duration
                FROM media_legacy
            SQL);
            $this->pdo->exec('DROP TABLE media_legacy');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_media_type       ON media(type);
            CREATE INDEX IF NOT EXISTS idx_media_title      ON media(title);
            CREATE INDEX IF NOT EXISTS idx_media_year       ON media(year);
            CREATE INDEX IF NOT EXISTS idx_media_indexed_at ON media(indexed_at);
        SQL);
    }

    // ── View recreation ───────────────────────────────────────────────────────
    //
    // v_media is created once with IF NOT EXISTS — it is NEVER dropped here.
    //
    // The previous DROP+CREATE pattern had a race window: between the DROP and
    // the CREATE, any concurrent FPM request that opened a new Connection would
    // also run DROP+CREATE, and any scan.php query landing in that gap would see
    // "no such table: v_media".
    //
    // The only place v_media is ever dropped is inside versioned migrations
    // (migrate_v1 Step 4), which runs exactly once.  On every subsequent boot,
    // IF NOT EXISTS is a no-op and the view is never removed.

    private function recreateView(): void
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
                -- Movies-specific fields
                mm.director,
                mm.collection,
                -- Shows-specific fields
                ms.show_name,
                ms.season,
                ms.episode,
                ms.still,
                -- Books/audiobooks-specific fields
                mb.book_name,
                mb.book_version,
                mb.chapters,
                -- author: music→artist | movies→director | books/audiobooks→author
                CASE m.type
                    WHEN 'music'  THEN mu.artist
                    WHEN 'movies' THEN mm.director
                    ELSE mb.author
                END AS author,
                -- series: music→album | movies→collection | books/audiobooks→series
                CASE m.type
                    WHEN 'music'  THEN mu.album
                    WHEN 'movies' THEN mm.collection
                    ELSE mb.series
                END AS series,
                -- series_order: music→track_order | books/audiobooks→series_order
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
