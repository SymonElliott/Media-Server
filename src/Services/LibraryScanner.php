<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LibraryScanner
{
    private const EXTENSIONS = [
        'movies'     => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'shows'      => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
        'music'      => ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav'],
        'audiobooks' => ['mp3', 'flac', 'aac', 'm4a', 'ogg', 'wav', 'm4b'],
        'books'      => ['pdf', 'epub', 'mobi', 'azw', 'azw3', 'cbr', 'cbz'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly string $libraryPath
    ) {}

    public function scan(): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (array_keys(self::EXTENSIONS) as $type) {
            $typePath = $this->libraryPath . '/' . $type;
            if (!is_dir($typePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($typePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $ext = strtolower($file->getExtension());
                if (!in_array($ext, self::EXTENSIONS[$type], true)) {
                    continue;
                }

                try {
                    $isNew = $this->indexFile($file, $type);
                    $isNew ? $stats['added']++ : $stats['updated']++;
                } catch (\Exception) {
                    $stats['skipped']++;
                }
            }
        }

        return $stats;
    }

    private function indexFile(SplFileInfo $file, string $type): bool
    {
        $path = $file->getRealPath();
        $meta = $this->extractMeta($file, $type);

        $existing = $this->db->first('SELECT id FROM media WHERE path = ?', [$path]);

        $this->db->execute(<<<SQL
            INSERT INTO media (type, path, filename, extension, size, title, author, series, show_name, season, episode)
            VALUES (:type, :path, :filename, :extension, :size, :title, :author, :series, :show_name, :season, :episode)
            ON CONFLICT(path) DO UPDATE SET
                size       = excluded.size,
                indexed_at = CURRENT_TIMESTAMP
        SQL, [
            'type'      => $type,
            'path'      => $path,
            'filename'  => $file->getFilename(),
            'extension' => strtolower($file->getExtension()),
            'size'      => $file->getSize(),
            ...$meta,
        ]);

        return $existing === null;
    }

    private function extractMeta(SplFileInfo $file, string $type): array
    {
        $relative = ltrim(str_replace($this->libraryPath . '/' . $type, '', $file->getPath()), '/');
        $parts    = array_values(array_filter(explode('/', $relative)));

        return match ($type) {
            'shows' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => null,
                'series'    => null,
                'show_name' => $parts[0] ?? null,
                'season'    => isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : null,
                'episode'   => null,
            ],
            'books', 'audiobooks' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => $parts[0] ?? null,
                'series'    => $parts[1] ?? null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
            'music' => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => $parts[0] ?? null,
                'series'    => $parts[1] ?? null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
            default => [
                'title'     => $file->getBasename('.' . $file->getExtension()),
                'author'    => null,
                'series'    => null,
                'show_name' => null,
                'season'    => null,
                'episode'   => null,
            ],
        };
    }
}
