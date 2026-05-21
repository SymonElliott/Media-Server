#!/usr/bin/env php
<?php
/**
 * Normalizes book format subdirectory names across the library:
 *   EPUB / epub / Epub / … → Ebook
 *   Audible / MP3 / Audio / … → Audiobook
 *
 * Also updates the DB so paths and book_version stay in sync.
 *
 * Usage:  php bin/normalize_book_dirs.php [--dry-run]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dryRun   = in_array('--dry-run', $argv, true);

$db       = new App\Database\Connection($root . '/storage/db/media.sqlite');
$booksDir = $root . '/library/books';

if (!is_dir($booksDir)) {
    fwrite(STDERR, "Books directory not found: $booksDir\n");
    exit(1);
}

// ── Rename rules ─────────────────────────────────────────────────────────────

$audioExts = ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'];
$ebookExts = ['epub', 'pdf', 'mobi', 'azw', 'azw3', 'cbr', 'cbz'];

// Dir names (case-insensitive) that should be renamed to their canonical form.
$renameToEbook     = ['epub', 'epubs', 'ebook', 'ebooks', 'pdf'];
$renameToAudiobook = ['mp3', 'mp3s', 'audible', 'audio', 'audiobook', 'audiobooks', 'aax'];

// ── Walk the books tree looking for format dirs ───────────────────────────────
// Format dirs sit at depth ≥ 2 (Author/Book/FormatDir or Author/Series/Book/FormatDir)
// and contain only files (no sub-directories).

function findFormatDirs(string $path, int $depth = 0): Generator
{
    $entries = array_diff(scandir($path), ['.', '..', '.DS_Store']);

    $hasFiles = false;
    $subDirs  = [];
    foreach ($entries as $name) {
        if ($name[0] === '.') continue;
        $full = "$path/$name";
        if (is_file($full)) {
            $hasFiles = true;
        } elseif (is_dir($full)) {
            $subDirs[] = $name;
        }
    }

    // A directory that contains only files and sits at depth ≥ 2 is a format dir.
    if ($hasFiles && empty($subDirs) && $depth >= 2) {
        yield $path;
        return;
    }

    if ($depth < 5) {
        foreach ($subDirs as $name) {
            yield from findFormatDirs("$path/$name", $depth + 1);
        }
    }
}

// ── Determine target name for a format dir ────────────────────────────────────

function targetName(string $dirPath, array $audioExts, array $ebookExts): ?string
{
    $files = array_values(array_filter(
        array_diff(scandir($dirPath), ['.', '..', '.DS_Store']),
        fn($f) => is_file("$dirPath/$f")
    ));

    if (empty($files)) {
        return null;
    }

    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $audioExts, true)) {
            return 'Audiobook';
        }
        if (in_array($ext, $ebookExts, true)) {
            return 'Ebook';
        }
    }

    return null;
}

// ── Main loop ─────────────────────────────────────────────────────────────────

$renamed  = 0;
$skipped  = 0;
$conflict = 0;

foreach (findFormatDirs($booksDir) as $formatPath) {
    $dirName   = basename($formatPath);
    $lowerName = strtolower($dirName);

    $target = targetName($formatPath, $audioExts, $ebookExts);

    if ($target === null) {
        continue; // Unknown file types — leave alone.
    }

    // Decide whether this dir needs renaming.
    if ($dirName === $target) {
        $skipped++;
        continue; // Already correct.
    }

    $shouldRename = match ($target) {
        'Ebook'     => in_array($lowerName, $renameToEbook,     true),
        'Audiobook' => in_array($lowerName, $renameToAudiobook, true),
        default     => false,
    };

    if (!$shouldRename) {
        // The dir has a custom / descriptive name — leave it.
        $skipped++;
        continue;
    }

    $parentPath = dirname($formatPath);
    $newPath    = "$parentPath/$target";

    if (file_exists($newPath)) {
        echo "CONFLICT: $newPath already exists — skipping $formatPath\n";
        $conflict++;
        continue;
    }

    $action = $dryRun ? 'Would rename' : 'Renamed';
    echo "$action: $formatPath  →  $newPath\n";

    if (!$dryRun) {
        rename($formatPath, $newPath);

        // Update every DB row whose path was under $formatPath.
        $rows = $db->query(
            'SELECT id, path, book_version FROM media WHERE path LIKE ?',
            [$formatPath . '/%']
        );

        foreach ($rows as $row) {
            $newFilePath    = $newPath . substr($row['path'], strlen($formatPath));
            $newBookVersion = $target;
            $db->execute(
                'UPDATE media SET path = ?, book_version = ? WHERE id = ?',
                [$newFilePath, $newBookVersion, $row['id']]
            );
        }
    }

    $renamed++;
}

echo "\nDone. Renamed: $renamed  |  Skipped (already correct / custom name): $skipped  |  Conflicts: $conflict\n";
if ($dryRun) {
    echo "(Dry-run — no changes written)\n";
}
