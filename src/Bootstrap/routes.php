<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\LibraryController;
use App\Controllers\MediaController;
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
    $app->get('/scan/status', LibraryController::class . ':status');

    $app->post('/metadata/refresh/{id}', LibraryController::class . ':refreshMetadata');
    $app->post('/metadata/refresh-series', LibraryController::class . ':refreshSeriesMetadata');
    $app->post('/metadata/refresh-type/{type}', LibraryController::class . ':refreshTypeMetadata');

    // Media CRUD + metadata search/match API
    $app->get('/api/metadata/search', MediaController::class . ':searchMetadata');
    $app->delete('/api/media/group', MediaController::class . ':deleteGroup');
    $app->get('/api/media/{id}', MediaController::class . ':get');
    $app->patch('/api/media/{id}', MediaController::class . ':update');
    $app->delete('/api/media/{id}', MediaController::class . ':delete');
    $app->post('/api/media/{id}/match', MediaController::class . ':applyMatch');
};
