<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../src/Bootstrap/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addRoutingMiddleware();
$debug = $container->get(\App\Services\Settings::class)->getEnv('APP_DEBUG', 'false') === 'true';
$app->addErrorMiddleware($debug, true, true);

(require __DIR__ . '/../src/Bootstrap/routes.php')($app);

$app->run();
