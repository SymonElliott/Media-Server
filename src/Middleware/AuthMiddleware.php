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

        // Generate the CSRF token once per session and expose it as a Twig global
        // so {{ csrf_token }} works in every template without any per-controller wiring.
        // This must happen here (outermost middleware, after session_start) so the token
        // is available when CsrfMiddleware validates incoming POST requests.
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->twig->addGlobal('csrf_token', $_SESSION['csrf_token']);

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
