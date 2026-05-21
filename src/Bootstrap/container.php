<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ProgressController;
use App\Controllers\LibraryController;
use App\Controllers\MediaController;
use App\Controllers\PeopleController;
use App\Controllers\RealDebridController;
use App\Controllers\SettingsController;
use App\Controllers\UploadController;
use App\Controllers\UsersController;
use App\Middleware\AuthMiddleware;
use App\Services\PeopleService;
use App\Database\Connection;
use App\Services\LibraryScanner;
use App\Services\Metadata\AudnexusProvider;
use App\Services\Metadata\MetadataService;
use App\Services\Metadata\MusicBrainzProvider;
use App\Services\Metadata\OpenLibraryProvider;
use App\Services\Metadata\TmdbProvider;
use App\Services\AppLogger;
use App\Services\RealDebridService;
use App\Services\RenameService;
use App\Services\Settings;
use App\Services\StreamService;
use GuzzleHttp\Client;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Library is always the `library/` directory at the application root.
// Mount your media there in docker-compose (or create a symlink for local dev).
// No user-configurable path setting is needed.
$libraryRoot = dirname(__DIR__, 2) . '/library';

return [
    AppLogger::class => function () {
        return new AppLogger(__DIR__ . '/../../storage/app.log');
    },

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

    Settings::class => function ($c) {
        return new Settings($c->get(Connection::class));
    },

    RenameService::class => function ($c) use ($libraryRoot) {
        return new RenameService(
            $c->get(Connection::class),
            $c->get(Settings::class),
            $libraryRoot
        );
    },

    LibraryScanner::class => function ($c) use ($libraryRoot) {
        return new LibraryScanner(
            $c->get(Connection::class),
            $libraryRoot
        );
    },

    StreamService::class => function ($c) use ($libraryRoot) {
        return new StreamService($libraryRoot);
    },

    Client::class => function () {
        return new Client(['timeout' => 15, 'http_errors' => false]);
    },

    TmdbProvider::class => function ($c) {
        return new TmdbProvider($c->get(Client::class), $c->get(Settings::class)->getEnv('TMDB_API_KEY'));
    },

    MusicBrainzProvider::class => function ($c) {
        return new MusicBrainzProvider($c->get(Client::class), 'MediaServer/1.0');
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
            __DIR__ . '/../../public/covers',
            $c->get(RenameService::class)
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

    PeopleController::class => function ($c) use ($libraryRoot) {
        return new PeopleController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(PeopleService::class),
            $libraryRoot
        );
    },

    MediaController::class => function ($c) {
        return new MediaController(
            $c->get(Connection::class),
            $c->get(MetadataService::class),
            $c->get(RenameService::class),
            $c->get(AppLogger::class)
        );
    },

    RealDebridService::class => function ($c) {
        return new RealDebridService(
            $c->get(Client::class),
            $c->get(Settings::class)->getEnv('REAL_DEBRID_API_KEY')
        );
    },

    RealDebridController::class => function ($c) {
        return new RealDebridController(
            $c->get(Connection::class),
            $c->get(RealDebridService::class)
        );
    },

    SettingsController::class => function ($c) {
        return new SettingsController(
            $c->get(Environment::class),
            $c->get(Settings::class),
            $c->get(AppLogger::class)
        );
    },

    HomeController::class => function ($c) use ($libraryRoot) {
        return new HomeController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $libraryRoot
        );
    },

    AuthController::class => function ($c) {
        return new AuthController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(AppLogger::class)
        );
    },

    ProgressController::class => function ($c) {
        return new ProgressController($c->get(Connection::class));
    },

    UsersController::class => function ($c) {
        return new UsersController(
            $c->get(Environment::class),
            $c->get(Connection::class)
        );
    },

    AuthMiddleware::class => function ($c) {
        return new AuthMiddleware(
            $c->get(Environment::class),
            $c->get(Connection::class)
        );
    },

    UploadController::class => function ($c) use ($libraryRoot) {
        return new UploadController(
            $c->get(MetadataService::class),
            $c->get(LibraryScanner::class),
            $libraryRoot,
            $c->get(AppLogger::class)
        );
    },

    LibraryController::class => function ($c) use ($libraryRoot) {
        return new LibraryController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(LibraryScanner::class),
            $c->get(MetadataService::class),
            $libraryRoot,
            $c->get(AppLogger::class)
        );
    },
];
