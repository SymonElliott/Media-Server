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

        // Enable foreign key constraints (required for ON DELETE CASCADE)
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
        // ── Core non-media tables ────────────────────────────────────────────
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

        // ── media base table (common fields only) ────────────────────────────
        // When upgrading from the old flat schema, this CREATE is a no-op because
        // the old table already exists; the migration below strips the type-specific
        // columns once extension tables are populated.
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

        // ── Type-specific extension tables (one-to-one with media) ───────────
        $this->pdo->exec(<<<'SQL'
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

        // ── One-time migration from old flat schema ───────────────────────────
        // Detect the old schema by checking whether 'show_name' still lives on media.
        $mediaColumns = array_column(
            $this->pdo->query('PRAGMA table_info(media)')->fetchAll(),
            'name'
        );
        if (in_array('show_name', $mediaColumns, true)) {
            $this->migrateToExtensionTables();
        }

        // ── Legacy column additions for pre-extension DBs ────────────────────
        // Safe to run every time (no-ops if column already exists or was removed
        // by the extension-table migration above).
        $mediaColumns = array_column(
            $this->pdo->query('PRAGMA table_info(media)')->fetchAll(),
            'name'
        );
        foreach (['duration' => 'INTEGER'] as $col => $type) {
            if (!in_array($col, $mediaColumns, true)) {
                $this->pdo->exec("ALTER TABLE media ADD COLUMN $col $type");
            }
        }

        // series_meta additions
        $smColumns = array_column(
            $this->pdo->query('PRAGMA table_info(series_meta)')->fetchAll(),
            'name'
        );
        foreach (['author_image' => 'TEXT', 'author_asin' => 'TEXT'] as $col => $type) {
            if (!in_array($col, $smColumns, true)) {
                $this->pdo->exec("ALTER TABLE series_meta ADD COLUMN $col $type");
            }
        }

        // ── Repair a view broken by SQLite 3.26+ rename-rewriting ───────────
        // SQLite 3.26.0+ auto-rewrites view SQL when a referenced table is renamed.
        // Boots before 1.2.3 could rename 'media' → 'media_legacy' while v_media
        // existed, leaving the view permanently pointing at the now-deleted table.
        // The 1.2.3 fix drops v_media inside the migration transaction (before RENAME),
        // but if the migration already completed on an earlier boot the guard never fires.
        // We detect and repair the stale view here, unconditionally on every boot.
        $staleViewSql = $this->pdo->query(
            "SELECT sql FROM sqlite_schema WHERE type='view' AND name='v_media'"
        )->fetchColumn();
        if (is_string($staleViewSql) && str_contains($staleViewSql, 'media_legacy')) {
            // The view is broken; drop it so the CREATE VIEW IF NOT EXISTS below
            // recreates it with the correct definition. The broken view was already
            // making every query fail, so this DROP+CREATE is a strict improvement.
            $this->pdo->exec('DROP VIEW IF EXISTS v_media');
        }

        // ── v_media view ──────────────────────────────────────────────────────
        // Use IF NOT EXISTS — never drop a healthy view.
        //
        // Dropping on every boot creates a race window: a long-running scan.php
        // query against v_media can land between the DROP and the subsequent
        // CREATE of a concurrent HTTP request, producing "no such table: v_media".
        //
        // Definition changes should use a versioned migration gate (like
        // migrateToExtensionTables) — or add a targeted repair block like the one
        // above.
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
                -- Shows-specific fields
                ms.show_name,
                ms.season,
                ms.episode,
                ms.still,
                -- Books/audiobooks-specific fields
                mb.book_name,
                mb.book_version,
                mb.chapters,
                -- author: music→artist  |  books/audiobooks→author
                CASE m.type WHEN 'music' THEN mu.artist ELSE mb.author END AS author,
                -- series: music→album   |  books/audiobooks→series
                CASE m.type WHEN 'music' THEN mu.album  ELSE mb.series END AS series,
                -- series_order: music→track_order  |  books/audiobooks→series_order
                CASE m.type WHEN 'music' THEN mu.track_order ELSE mb.series_order END AS series_order
            FROM media m
            LEFT JOIN media_shows ms ON ms.media_id = m.id
            LEFT JOIN media_music  mu ON mu.media_id = m.id
            LEFT JOIN media_books  mb ON mb.media_id = m.id
        SQL);
    }

    /**
     * One-time migration: populate extension tables from the old flat media table,
     * then recreate media with only the common base columns.
     *
     * Gated by the presence of 'show_name' on media; will never run twice.
     */
    private function migrateToExtensionTables(): void
    {
        // Populate extension tables from flat columns.
        // INSERT OR IGNORE so a partial previous run is safe to retry.
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
        // Use a transaction so no partial state is left on failure.
        $this->pdo->beginTransaction();
        try {
            // SQLite 3.26.0+ automatically rewrites view SQL when a table is renamed.
            // If v_media already exists, the RENAME below would silently change every
            // "FROM media" in the view to "FROM media_legacy".  After we subsequently
            // DROP TABLE media_legacy the view becomes permanently broken, and our
            // "CREATE VIEW IF NOT EXISTS" later is a no-op (view still "exists").
            // Fix: drop v_media inside this transaction BEFORE the rename.
            // On commit  → view is gone; migrate() recreates it cleanly afterward.
            // On rollback → the DROP is also rolled back; view is restored intact.
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

        // Recreate indexes on the new table.
        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_media_type       ON media(type);
            CREATE INDEX IF NOT EXISTS idx_media_title      ON media(title);
            CREATE INDEX IF NOT EXISTS idx_media_year       ON media(year);
            CREATE INDEX IF NOT EXISTS idx_media_indexed_at ON media(indexed_at);
        SQL);
    }
}
