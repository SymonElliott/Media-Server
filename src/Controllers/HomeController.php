<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HomeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $rows   = $this->db->query('SELECT type, COUNT(*) as count, SUM(size) as total_size FROM media GROUP BY type');
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] = $row;
        }
        // Audiobooks live in the books directory — merge their count into books
        if (isset($counts['audiobooks'])) {
            $counts['books']['count']      = ($counts['books']['count']      ?? 0) + $counts['audiobooks']['count'];
            $counts['books']['total_size'] = ($counts['books']['total_size'] ?? 0) + $counts['audiobooks']['total_size'];
            unset($counts['audiobooks']);
        }

        $html = $this->twig->render('home.html.twig', ['counts' => $counts]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
