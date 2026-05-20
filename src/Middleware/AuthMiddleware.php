<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Twig\Environment;

class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PATHS = ['/login', '/logout', '/setup'];

    public function __construct(
        private readonly Environment $twig,
        private readonly Connection  $db,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!session_id()) {
            session_set_cookie_params(['samesite' => 'Lax', 'httponly' => true]);
            session_start();
        }

        $path     = $request->getUri()->getPath();
        $isPublic = in_array($path, self::PUBLIC_PATHS, true);

        if (!$isPublic && empty($_SESSION['user'])) {
            return (new Response())->withHeader('Location', '/login')->withStatus(302);
        }

        if (!empty($_SESSION['user'])) {
            $this->twig->addGlobal('authUser', $_SESSION['user']);
        }

        return $handler->handle($request);
    }
}
