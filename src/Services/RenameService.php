<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

class RenameService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Settings   $settings,
        private readonly string     $libraryPath
    ) {}

    // ── Public entry points ───────────────────────────────────────────────

    public function renameItem(int $id): void
    {
        if (!$this->settings->getBool('auto_rename')) return;
        $item = $this->db->first('SELECT * FROM media WHERE id = ?', [$id]);
        if ($item) $this->doRename($item);
    }

    public function renameShowEpisodes(string $showName): void
    {
        if (!$this->settings->getBool('auto_rename')) return;
        foreach ($this->db->query('SELECT * FROM media WHERE type = "shows" AND show_name = ?', [$showName]) as $item) {
            $this->doRename($item);
        }
    }

    public function renameBookFiles(string $bookName): void
    {
        if (!$this->settings->getBool('auto_rename')) return;
        foreach ($this->db->query('SELECT * FROM media WHERE type = "audiobooks" AND book_name = ?', [$bookName]) as $item) {
            $this->doRename($item);
        }
    }

    public function renameAlbumTracks(string $artist, string $album): void
    {
        if (!$this->settings->getBool('auto_rename')) return;
        foreach ($this->db->query('SELECT * FROM media WHERE type = "music" AND author = ? AND series = ?', [$artist, $album]) as $item) {
            $this->doRename($item);
        }
    }

    // ── Core rename ───────────────────────────────────────────────────────

    private function doRename(array $item): void
    {
        $currentPath = $item['path'];
        if (!file_exists($currentPath)) return;

        $targetPath = $this->canonicalPath($item);
        if (!$targetPath || $targetPath === $currentPath) return;

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) return;

        $ext        = strtolower($item['extension'] ?? pathinfo($currentPath, PATHINFO_EXTENSION));
        $targetPath = $this->uniquePath($targetPath, $ext);

        if (!@rename($currentPath, $targetPath)) return;

        $this->db->execute(
            'UPDATE media SET path = ?, filename = ? WHERE id = ?',
            [$targetPath, basename($targetPath), $item['id']]
        );

        $this->cleanEmptyDirs(dirname($currentPath), dirname($targetPath));
    }

    // ── Canonical path computation ─────────────────────────────────────────

    private function canonicalPath(array $item): ?string
    {
        $ext = strtolower($item['extension'] ?? pathinfo($item['path'], PATHINFO_EXTENSION));
        return match ($item['type']) {
            'movies'     => $this->moviePath($item, $ext),
            'shows'      => $this->showPath($item, $ext),
            'books'      => $this->singleBookPath($item, $ext),
            'audiobooks' => $this->singleBookPath($item, $ext),
            'music'      => $this->musicPath($item, $ext),
            default      => null,
        };
    }

    private function moviePath(array $item, string $ext): ?string
    {
        $title = $item['title'] ?? null;
        if (!$title) return null;
        $year = $item['year'] ? ' (' . $item['year'] . ')' : '';
        $name = $this->safe($title) . $year;
        return $this->libraryPath . '/movies/' . $name . '/' . $name . '.' . $ext;
    }

    private function showPath(array $item, string $ext): ?string
    {
        $show    = $item['show_name'] ?? null;
        $season  = $item['season']    ?? null;
        $episode = $item['episode']   ?? null;
        if (!$show || $season === null || $episode === null) return null;

        // Include first-air year in the folder (and file) name once metadata is known.
        // year is NULL until TMDB enrichment runs, so pre-metadata files are untouched.
        $year    = $item['year'] ? ' (' . $item['year'] . ')' : '';
        $showDir = $this->safe($show) . $year;
        $s       = str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        $e       = str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        $title   = $item['title'] ? ' - ' . $this->safe($item['title']) : '';
        $file    = $showDir . ' - S' . $s . 'E' . $e . $title . '.' . $ext;
        return $this->libraryPath . '/shows/' . $showDir . '/Season ' . $s . '/' . $file;
    }

    private function singleBookPath(array $item, string $ext): ?string
    {
        $author = $item['author']    ?? null;
        $book   = $item['book_name'] ?? $item['title'] ?? null;
        if (!$author || !$book) return null;

        $authorDir = $this->safe($author);
        $bookDir   = $this->safe($book);
        return $this->libraryPath . '/books/' . $authorDir . '/' . $bookDir . '/' . $bookDir . '.' . $ext;
    }

    private function musicPath(array $item, string $ext): ?string
    {
        $artist = $item['author'] ?? null;
        if (!$artist) return null;
        $album  = $item['series'] ?? 'Unknown Album';
        $title  = $item['title']  ?? pathinfo($item['filename'], PATHINFO_FILENAME);
        return $this->libraryPath . '/music/' . $this->safe($artist) . '/' . $this->safe($album) . '/' . $this->safe($title) . '.' . $ext;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function safe(string $name): string
    {
        $name = preg_replace('/[\/\\\\\0\:\*\?\"\<\>\|]/', '', $name);
        $name = preg_replace('/\s{2,}/', ' ', $name);
        return trim($name, " .\t\n\r\0\x0B") ?: 'unknown';
    }

    private function uniquePath(string $path, string $ext): string
    {
        if (!file_exists($path)) return $path;
        $base = substr($path, 0, -strlen('.' . $ext));
        $i    = 2;
        while (file_exists("$base ($i).$ext")) $i++;
        return "$base ($i).$ext";
    }

    private function cleanEmptyDirs(string $oldDir, string $newDir): void
    {
        if ($oldDir === $newDir || !is_dir($oldDir)) return;
        $remaining = array_diff(scandir($oldDir), ['.', '..', '.DS_Store']);
        if (!empty($remaining)) return;
        @rmdir($oldDir);
        $parent = dirname($oldDir);
        if ($parent === $newDir || !is_dir($parent)) return;
        $parentRemaining = array_diff(scandir($parent), ['.', '..', '.DS_Store']);
        if (empty($parentRemaining)) @rmdir($parent);
    }
}
