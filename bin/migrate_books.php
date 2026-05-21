#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Consolidate audiobooks + books into a single /library/books/ tree.
 *
 * New structure: Author/[Series/]BookTitle/Version/files
 *
 * Usage:
 *   php bin/migrate_books.php           # live run, clears DB rows after
 *   php bin/migrate_books.php --dry-run # preview only, no files moved
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$db          = new App\Database\Connection($root . '/storage/db/media.sqlite');
$settings    = new App\Services\Settings($db);
$libraryPath = $settings->getEnv('MEDIA_PATH', '/media');
$audiobooksDir = $libraryPath . '/audiobooks';
$booksDir      = $libraryPath . '/books';
$dryRun        = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No files will be moved.\n\n";
}

$audioExts = ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'];
$textExts  = ['pdf', 'epub', 'mobi', 'azw', 'azw3', 'cbr', 'cbz'];

// Known version dir names — used to detect already-migrated files
$knownVersions = ['Audible', 'Kindle', 'EPUB', 'PDF', 'CBR', 'CBZ', 'MP3', 'FLAC', 'AAC', 'M4A', 'OGG', 'WAV'];

function versionDirName(string $ext): string
{
    return match (strtolower($ext)) {
        'm4b'                 => 'Audible',
        'mobi', 'azw', 'azw3' => 'Kindle',
        default               => strtoupper($ext),
    };
}

function cleanBookDirName(string $filename, string $fileType = 'audio'): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    // Strip Audible ASIN pattern [BXXXXXXXXXX]
    $base = trim(preg_replace('/\s*\[B[0-9A-Z]{9}\]\s*$/', '', $base));
    // Strip bare numeric ISBN-10/13 in brackets
    $base = trim(preg_replace('/\s*\[\d{10}(?:\d{3})?\]\s*$/', '', $base));
    // For ebooks: strip trailing " - Author Name" (everything after last " - ")
    if ($fileType === 'text') {
        $cleaned = trim(preg_replace('/\s+-\s+[^-]+$/', '', $base));
        if ($cleaned !== '') {
            $base = $cleaned;
        }
    }
    return $base ?: pathinfo($filename, PATHINFO_FILENAME);
}

function doMove(string $from, string $to, bool $dryRun): bool
{
    $shortFrom = preg_replace('#.+/library/#', 'library/', $from);
    $shortTo   = preg_replace('#.+/library/#', 'library/', $to);

    if ($dryRun) {
        echo "  DRY: $shortFrom\n       -> $shortTo\n";
        return true;
    }

    if (file_exists($to)) {
        echo "  SKIP (already exists): " . basename($to) . "\n";
        return false;
    }

    $toDir = dirname($to);
    if (!is_dir($toDir) && !mkdir($toDir, 0755, true)) {
        echo "  ERROR: Cannot create directory: $toDir\n";
        return false;
    }

    if (rename($from, $to)) {
        echo "  MOVED: $shortFrom\n        -> $shortTo\n";
        return true;
    }

    echo "  ERROR: rename() failed for $shortFrom\n";
    return false;
}

// ── Phase 1: Migrate /library/audiobooks/ ─────────────────────────────────────
if (is_dir($audiobooksDir)) {
    echo "=== Phase 1: Migrating audiobooks ===\n";

    foreach (array_diff(scandir($audiobooksDir), ['.', '..', '.DS_Store']) as $authorName) {
        $authorPath = $audiobooksDir . '/' . $authorName;
        if (!is_dir($authorPath)) {
            continue;
        }

        echo "\nAuthor: $authorName\n";

        // M4Bs sitting directly in the author directory (no series folder)
        foreach (glob($authorPath . '/*.m4b') ?: [] as $m4bPath) {
            $filename = basename($m4bPath);
            $bookDir  = cleanBookDirName($filename, 'audio');
            $target   = "$booksDir/$authorName/$bookDir/Audible/$filename";
            doMove($m4bPath, $target, $dryRun);
        }

        // Process subdirectories — each is either a series or a chapter-book directory
        foreach (array_diff(scandir($authorPath), ['.', '..', '.DS_Store']) as $subName) {
            $subPath = $authorPath . '/' . $subName;
            if (!is_dir($subPath)) {
                continue;
            }

            $subEntries = array_diff(scandir($subPath), ['.', '..', '.DS_Store']);
            $subFiles   = array_filter($subEntries, fn($f) => is_file($subPath . '/' . $f));

            $m4bFiles   = array_filter($subFiles, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b');
            $chapterFiles = array_filter($subFiles, fn($f) => in_array(
                strtolower(pathinfo($f, PATHINFO_EXTENSION)),
                array_diff($audioExts, ['m4b']),
                true
            ));

            if (!empty($m4bFiles)) {
                // Directory is a series — each M4B is a distinct book
                echo "  Series: $subName\n";
                foreach ($m4bFiles as $m4bFile) {
                    $bookDir = cleanBookDirName($m4bFile, 'audio');
                    $target  = "$booksDir/$authorName/$subName/$bookDir/Audible/$m4bFile";
                    doMove($subPath . '/' . $m4bFile, $target, $dryRun);
                }
            } elseif (!empty($chapterFiles)) {
                // Directory is a book — files are chapters
                $firstFile = reset($chapterFiles);
                $ext       = strtolower(pathinfo($firstFile, PATHINFO_EXTENSION));
                $version   = versionDirName($ext);
                echo "  Book (chapters): $subName / $version\n";
                foreach ($chapterFiles as $chFile) {
                    $target = "$booksDir/$authorName/$subName/$version/$chFile";
                    doMove($subPath . '/' . $chFile, $target, $dryRun);
                }
            } else {
                echo "  Skipping (no recognised audio files): $authorName/$subName\n";
            }
        }
    }
} else {
    echo "No audiobooks directory at $audiobooksDir — skipping Phase 1.\n";
}

// ── Phase 2: Restructure /library/books/ ─────────────────────────────────────
echo "\n=== Phase 2: Restructuring books ===\n";

// Collect all moves first so the iterator isn't affected while we rename dirs
$moves = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($booksDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $filename = $file->getFilename();
    if ($filename[0] === '.') {
        continue;
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, array_merge($audioExts, $textExts), true)) {
        continue;
    }

    // Determine relative directory path inside books/
    $relative = ltrim(str_replace(rtrim($booksDir, '/') . '/', '', $file->getPath()), '/');
    $parts    = $relative !== '' ? array_values(array_filter(explode('/', $relative))) : [];
    $depth    = count($parts);

    // Skip hidden directories (e.g. .calnotes)
    $hidden = false;
    foreach ($parts as $part) {
        if ($part[0] === '.') {
            $hidden = true;
            break;
        }
    }
    if ($hidden) {
        continue;
    }

    // Skip files that are already inside a known version directory
    if (!empty($parts) && in_array(end($parts), $knownVersions, true)) {
        continue;
    }

    $fileType = in_array($ext, $textExts, true) ? 'text' : 'audio';
    $version  = versionDirName($ext);
    $bookDir  = cleanBookDirName($filename, $fileType);

    if ($depth === 1) {
        // books/Author/file.ext  →  books/Author/BookTitle/Version/file.ext
        $author  = $parts[0];
        $target  = "$booksDir/$author/$bookDir/$version/$filename";
        $moves[] = [$file->getRealPath(), $target];
    } elseif ($depth === 2) {
        // books/Author/Series/file.ext  →  books/Author/Series/BookTitle/Version/file.ext
        $author  = $parts[0];
        $series  = $parts[1];
        $target  = "$booksDir/$author/$series/$bookDir/$version/$filename";
        $moves[] = [$file->getRealPath(), $target];
    } else {
        echo "  Skipping (unexpected depth $depth): $relative/$filename\n";
    }
}

foreach ($moves as [$from, $to]) {
    doMove($from, $to, $dryRun);
}

// ── Phase 3: Clear stale DB rows ─────────────────────────────────────────────
if (!$dryRun) {
    echo "\n=== Phase 3: Clearing books/audiobooks from DB ===\n";
    $dbPath = $root . '/storage/db/media.sqlite';
    if (file_exists($dbPath)) {
        $pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->exec('DELETE FROM media WHERE type IN ("books", "audiobooks")');
        echo "DB rows cleared. Run 'php bin/scan.php books' to re-index.\n";
    } else {
        echo "No DB found at $dbPath — skipping.\n";
    }
}

echo "\n=== Done! ===\n";
if ($dryRun) {
    echo "Re-run without --dry-run to apply changes.\n";
}
