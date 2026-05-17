<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\StreamService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StreamController
{
    public function __construct(private readonly StreamService $streamService) {}

    public function stream(Request $request, Response $response, array $args): Response
    {
        return $this->streamService->stream($request, $response, $args['path'] ?? '');
    }
}
