<?php
// PHP built-in server router: serve static files directly, everything else via Slim.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // serve the file as-is
}
require __DIR__ . '/public/index.php';
