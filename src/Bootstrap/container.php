<?php

declare(strict_types=1);

use App\Controllers\LibraryController;
use App\Controllers\MediaController;
use App\Controllers\PeopleController;
use App\Controllers\UploadController;
use App\Services\PeopleService;
use App\Database\Connection;
use App\Services\LibraryScanner;
use App\Services\Metadata\AudnexusProvider;
use App\Services\Metadata\MetadataService;
use App\Services\Metadata\MusicBrainzProvider;
use App\Services\Metadata\OpenLibraryProvider;
use App\Services\Metadata\TmdbProvider;
use App\Services\StreamService;
use GuzzleHttp\Client;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    Environment::class => function () {
        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        $env    = new Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
        ]);
        $env->addFilter(new \Twig\TwigFilter('json_decode', fn($v) => json_decode($v ?? '{}', true) ?? []));
        $env->addFilter(new \Twig\TwigFilter('filesize', function (?int $bytes): string {
            if ($bytes === null || $bytes <= 0) return '0 B';
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = (int) floor(log($bytes, 1024));
            $i = min($i, count($units) - 1);
            $val = $bytes / (1024 ** $i);
            return ($i === 0 ? (string) $bytes : number_format($val, $val < 10 ? 2 : 1)) . ' ' . $units[$i];
        }));
        return $env;
    },

    Connection::class => function () {
        return new Connection(__DIR__ . '/../../storage/db/media.sqlite');
    },

    LibraryScanner::class => function ($c) {
        return new LibraryScanner(
            $c->get(Connection::class),
            $_ENV['LIBRARY_PATH'] ?? '/library'
        );
    },

    StreamService::class => function () {
        return new StreamService($_ENV['LIBRARY_PATH'] ?? '/library');
    },

    Client::class => function () {
        return new Client(['timeout' => 15, 'http_errors' => false]);
    },

    TmdbProvider::class => function ($c) {
        return new TmdbProvider($c->get(Client::class), $_ENV['TMDB_API_KEY'] ?? '');
    },

    MusicBrainzProvider::class => function ($c) {
        return new MusicBrainzProvider($c->get(Client::class), $_ENV['MUSICBRAINZ_USER_AGENT'] ?? 'MediaServer/1.0');
    },

    OpenLibraryProvider::class => function ($c) {
        return new OpenLibraryProvider($c->get(Client::class));
    },

    AudnexusProvider::class => function ($c) {
        return new AudnexusProvider($c->get(Client::class));
    },

    MetadataService::class => function ($c) {
        return new MetadataService(
            $c->get(Connection::class),
            $c->get(TmdbProvider::class),
            $c->get(MusicBrainzProvider::class),
            $c->get(OpenLibraryProvider::class),
            $c->get(AudnexusProvider::class),
            $c->get(Client::class),
            __DIR__ . '/../../public/covers'
        );
    },

    PeopleService::class => function ($c) {
        return new PeopleService(
            $c->get(Connection::class),
            $c->get(TmdbProvider::class),
            $c->get(AudnexusProvider::class),
            $c->get(OpenLibraryProvider::class),
            $c->get(Client::class),
            __DIR__ . '/../../public/covers'
        );
    },

    PeopleController::class => function ($c) {
        return new PeopleController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(PeopleService::class),
            $_ENV['LIBRARY_PATH'] ?? '/library'
        );
    },

    MediaController::class => function ($c) {
        return new MediaController(
            $c->get(Connection::class),
            $c->get(MetadataService::class)
        );
    },

    UploadController::class => function ($c) {
        return new UploadController(
            $c->get(MetadataService::class),
            $c->get(LibraryScanner::class),
            $_ENV['LIBRARY_PATH'] ?? '/library'
        );
    },

    LibraryController::class => function ($c) {
        return new LibraryController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(LibraryScanner::class),
            $c->get(MetadataService::class),
            $_ENV['LIBRARY_PATH'] ?? '/library'
        );
    },
];
