<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CsrfMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // CSRF only applies to traditional HTML form submissions.
        //
        // JSON/API requests (Content-Type: application/json, empty-body POSTs,
        // etc.) are inherently protected by the browser's CORS same-origin
        // policy — a cross-site attacker cannot set Content-Type to
        // application/json in a cross-origin request — so no CSRF token is
        // needed.  Checking getParsedBody() for JSON requests always returns
        // null, which would wrongly block every scan/upload/API call.
        if ($request->getMethod() === 'POST') {
            $ct          = $request->getHeaderLine('Content-Type');
            $isFormPost  = str_contains($ct, 'application/x-www-form-urlencoded')
                        || str_contains($ct, 'multipart/form-data');

            if ($isFormPost) {
                $token = $request->getParsedBody()['csrf_token'] ?? '';

                if (empty($token)) {
                    $response = new \Slim\Psr7\Response();
                    $response->getBody()->write('CSRF token missing');
                    return $response->withStatus(403);
                }

                if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
                    $response = new \Slim\Psr7\Response();
                    $response->getBody()->write('Invalid CSRF token');
                    return $response->withStatus(403);
                }
            }
        }

        // Ensure a session token exists for use in form templates.
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $request = $request->withAttribute('csrf_token', $_SESSION['csrf_token']);

        return $handler->handle($request);
    }
}