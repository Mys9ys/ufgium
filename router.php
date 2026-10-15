<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}
if (str_starts_with($uri, '/api/')) {
    $_GET['r'] = trim(substr($uri, 5), '/');
    require __DIR__ . '/api/index.php';
    return true;
}
require __DIR__ . '/index.php';
return true;
