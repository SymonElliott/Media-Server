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
use App\Controllers\StreamController;
use App\Controllers\UploadController;
use App\Controllers\UsersController;
use App\Middleware\AuthMiddleware;
use Slim\App;

return function (App $app): void {
    $app->add($app->getContainer()->get(AuthMiddleware::class));

    // Public routes (no auth required — middleware self-exempts these)
    $app->get('/login',  AuthController::class . ':loginPage');
    $app->post('/login', AuthController::class . ':login');
    $app->post('/logout', AuthController::class . ':logout');
    $app->get('/setup',  AuthController::class . ':setupPage');
    $app->post('/setup', AuthController::class . ':setup');

    // User management
    $app->get('/users',              UsersController::class . ':index');
    $app->post('/users',             UsersController::class . ':create');
    $app->delete('/users/{id:\d+}',  UsersController::class . ':delete');
    $app->post('/users/password',    UsersController::class . ':changePassword');

    $app->get('/', HomeController::class . ':index');

    // Redirect old /library/audiobooks URLs to the consolidated /library/books
    $app->get('/library/audiobooks', function ($req, $res) {
        return $res->withHeader('Location', '/library/books')->withStatus(301);
    });
    $app->get('/library/audiobooks/{path:.*}', function ($req, $res, $args) {
        return $res->withHeader('Location', '/library/books/' . $args['path'])->withStatus(301);
    });

    $app->group('/library', function ($group) {
        $group->get('', LibraryController::class . ':index');
        $group->get('/{type}', LibraryController::class . ':browse');
        $group->get('/{type}/{path:.*}', LibraryController::class . ':item');
    });

    $app->get('/stream/{path:.*}', StreamController::class . ':stream');
    $app->get('/read/{type}/{path:.*}', LibraryController::class . ':readItem');
    $app->get('/convert/{type}/{path:.*}', LibraryController::class . ':convertItem');

    $app->post('/upload', UploadController::class . ':upload');

    $app->post('/rd/add', RealDebridController::class . ':add');
    $app->get('/rd/queue', RealDebridController::class . ':queue');
    $app->get('/rd/status/{id}', RealDebridController::class . ':status');
    $app->delete('/rd/{id}', RealDebridController::class . ':remove');

    $app->post('/scan', LibraryController::class . ':scan');
    $app->get('/scan/status', LibraryController::class . ':status');

    $app->post('/metadata/refresh/{id}', LibraryController::class . ':refreshMetadata');
    $app->post('/metadata/refresh-series', LibraryController::class . ':refreshSeriesMetadata');
    $app->post('/metadata/refresh-album', LibraryController::class . ':refreshAlbumMetadata');
    $app->post('/metadata/refresh-type/{type}', LibraryController::class . ':refreshTypeMetadata');

    $app->get('/settings', SettingsController::class . ':index');
    $app->post('/settings', SettingsController::class . ':save');

    $app->get('/people', PeopleController::class . ':browse');
    $app->get('/people/{slug}', PeopleController::class . ':detail');
    $app->post('/people/{id:\d+}/refresh', PeopleController::class . ':refresh');

    // Expandable row children
    $app->get('/api/children/{type}/{path:.*}', LibraryController::class . ':getChildren');

    // Progress tracking
    $app->get('/api/progress/{id:\d+}',    ProgressController::class . ':get');
    $app->put('/api/progress/{id:\d+}',    ProgressController::class . ':upsert');
    $app->delete('/api/progress/{id:\d+}', ProgressController::class . ':delete');

    // Media CRUD + metadata search/match API
    $app->get('/api/metadata/search', MediaController::class . ':searchMetadata');
    $app->delete('/api/media/group', MediaController::class . ':deleteGroup');
    $app->get('/api/media/{id}', MediaController::class . ':get');
    $app->patch('/api/media/{id}', MediaController::class . ':update');
    $app->delete('/api/media/{id}', MediaController::class . ':delete');
    $app->post('/api/media/{id}/match', MediaController::class . ':applyMatch');
};
