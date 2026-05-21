<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lightweight, append-only log file for the background scan process.
 *
 * scan.php writes phase banners and MetadataService writes per-item
 * enrichment results.  The frontend polls /scan/log?offset=N and only
 * receives bytes it hasn't seen yet, so reads are always O(new-data).
 */
class ScanLogger
{
    public function __construct(private readonly string $logFile) {}

    /** Wipe the log at the start of a new scan. */
    public function clear(): void
    {
        @file_put_contents($this->logFile, '');
    }

    /** Append a timestamped line. */
    public function info(string $message): void
    {
        file_put_contents(
            $this->logFile,
            date('[H:i:s]') . ' ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Read log content starting at $offset bytes.
     * Returns ['text' => string, 'size' => int].
     */
    public function tail(int $offset = 0): array
    {
        if (!file_exists($this->logFile)) {
            return ['text' => '', 'size' => 0];
        }

        clearstatcache(true, $this->logFile);
        $size = filesize($this->logFile);

        if ($offset >= $size) {
            return ['text' => '', 'size' => $size];
        }

        $fh = fopen($this->logFile, 'r');
        if ($offset > 0) {
            fseek($fh, $offset);
        }
        $text = fread($fh, $size - $offset);
        fclose($fh);

        return ['text' => (string) $text, 'size' => $size];
    }
}
