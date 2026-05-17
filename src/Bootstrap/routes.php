<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\LibraryController;
use App\Controllers\StreamController;
use Slim\App;

return function (App $app): void {
    $app->get('/', HomeController::class . ':index');

    $app->group('/library', function ($group) {
        $group->get('', LibraryController::class . ':index');
        $group->get('/{type}', LibraryController::class . ':browse');
        $group->get('/{type}/{path:.*}', LibraryController::class . ':item');
    });

    $app->get('/stream/{path:.*}', StreamController::class . ':stream');

    $app->post('/scan', LibraryController::class . ':scan');
};
