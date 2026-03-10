<?php

require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Utils.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/GameController.php';

$config = require __DIR__ . '/../src/config.php';

try {
   $database = new Database($config);
   $controller = new GameController($database->pdo());
} catch (Throwable $e) {
   Response::error(500, 'Database connection failed.');
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove trailing slash except root
if ($uri !== '/' && str_ends_with($uri, '/')) {
   $uri = rtrim($uri, '/');
}

// Health check
if ($method === 'GET' && $uri === '/health') {
   Response::json(200, ['status' => 'ok']);
}

// POST /games
if ($method === 'POST' && $uri === '/games') {
   $controller->createGame();
}

// POST /games/{gameId}/join
if ($method === 'POST' && preg_match('#^/games/([a-zA-Z0-9\-]+)/join$#', $uri, $matches)) {
   $controller->joinGame($matches[1]);
}

// GET /games/{gameId}
if ($method === 'GET' && preg_match('#^/games/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
   $controller->getGame($matches[1]);
}

Response::error(404, 'Endpoint not found.');

