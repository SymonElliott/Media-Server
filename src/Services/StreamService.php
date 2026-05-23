<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StreamService
{
    private const CHUNK_SIZE = 1024 * 1024; // 1 MB

    private const MIME_TYPES = [
        'mp4'  => 'video/mp4',
        'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',
        'mov'  => 'video/quicktime',
        'wmv'  => 'video/x-ms-wmv',
        'm4v'  => 'video/x-m4v',
        'mp3'  => 'audio/mpeg',
        'flac' => 'audio/flac',
        'aac'  => 'audio/aac',
        'm4a'  => 'audio/mp4',
        'm4b'  => 'audio/mp4',
        'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'pdf'  => 'application/pdf',
        'epub' => 'application/epub+zip',
    ];

    private readonly string $resolvedLibraryPath;

    public function __construct(private readonly string $libraryPath)
    {
        // Resolve once at construction time; every stream() call re-uses this.
        $this->resolvedLibraryPath = realpath($libraryPath) ?: $libraryPath;
    }

    public function stream(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $path
    ): ResponseInterface {
        $fullPath = realpath($this->libraryPath . '/' . ltrim($path, '/'));

        if ($fullPath === false || !str_starts_with($fullPath, $this->resolvedLibraryPath)) {
            return $response->withStatus(403);
        }

        if (!is_file($fullPath)) {
            return $response->withStatus(404);
        }

        $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $size = filesize($fullPath);

        $rangeHeader = $request->getHeaderLine('Range');

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start  = $m[1] !== '' ? (int) $m[1] : 0;
            $end    = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $end    = min($end, $size - 1);
            $length = $end - $start + 1;

            $response = $response
                ->withStatus(206)
                ->withHeader('Content-Range', "bytes $start-$end/$size")
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('Content-Length', (string) $length)
                ->withHeader('Content-Type', $mime);

            $fh        = fopen($fullPath, 'rb');
            $body      = $response->getBody();
            $remaining = $length;

            fseek($fh, $start);
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, min(self::CHUNK_SIZE, $remaining));
                $body->write($chunk);
                $remaining -= strlen($chunk);
            }
            fclose($fh);

            return $response;
        }

        $fh = fopen($fullPath, 'rb');
        return $response
            ->withStatus(200)
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($fullPath) . '"')
            ->withBody(new \Slim\Psr7\Stream($fh));
    }
}
