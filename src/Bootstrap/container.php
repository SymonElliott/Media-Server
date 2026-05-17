<?php

declare(strict_types=1);

use App\Controllers\LibraryController;
use App\Database\Connection;
use App\Services\LibraryScanner;
use App\Services\StreamService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    Environment::class => function () {
        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        return new Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
        ]);
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

    LibraryController::class => function ($c) {
        return new LibraryController(
            $c->get(Environment::class),
            $c->get(Connection::class),
            $c->get(LibraryScanner::class),
            $_ENV['LIBRARY_PATH'] ?? '/library'
        );
    },
];
