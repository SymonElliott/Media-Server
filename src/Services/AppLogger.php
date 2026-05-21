<?php

declare(strict_types=1);

namespace App\Services;

/**
 * General-purpose application event log.
 *
 * Entries are appended to storage/app.log with the format:
 *   [YYYY-MM-DD HH:MM:SS] [LEVEL] [channel] message
 *
 * Controllers call info/warn/error with a short channel name (auth, upload,
 * scan, metadata, media, settings, system).  The frontend polls /api/logs and
 * colours lines by channel and level.
 */
class AppLogger
{
    public function __construct(private readonly string $logFile) {}

    public function info(string $channel, string $message): void
    {
        $this->write('INFO ', $channel, $message);
    }

    public function warn(string $channel, string $message): void
    {
        $this->write('WARN ', $channel, $message);
    }

    public function error(string $channel, string $message): void
    {
        $this->write('ERROR', $channel, $message);
    }

    public function clear(): void
    {
        @file_put_contents($this->logFile, '');
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

    private function write(string $level, string $channel, string $message): void
    {
        $line = sprintf(
            "[%s] [%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $channel,
            $message
        );
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
