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
        // Skip CSRF check for API endpoints (those starting with /api/)
        $uri = $request->getUri();
        $path = $uri->getPath();
        
        if (str_starts_with($path, '/api/')) {
            return $handler->handle($request);
        }
        
        // For form submissions, check CSRF token
        if ($request->getMethod() === 'POST') {
            $token = $request->getParsedBody()['csrf_token'] ?? '';
            
            // If no token provided, reject the request
            if (empty($token)) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write('CSRF token missing');
                return $response->withStatus(403);
            }
            
            // Validate token (in a real implementation, this would check against session)
            // For now, we'll just check that it exists
            if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write('Invalid CSRF token');
                return $response->withStatus(403);
            }
        }
        
        // Generate new CSRF token for GET requests and store it in session
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Add CSRF token to the request attributes so it can be used in templates
        $request = $request->withAttribute('csrf_token', $_SESSION['csrf_token']);
        
        return $handler->handle($request);
    }
}