<?php

require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Utils.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/GameController.php';
require_once __DIR__ . '/../src/TestController.php';
require_once __DIR__ . '/../src/TestMode.php';

try {
    $database = new Database();
    $controller = new GameController($database->pdo());
    $testController = new TestController($database->pdo());
} catch (Throwable $e) {
    Response::error(500, 'Database connection failed.');
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/*
Support official /api prefix while keeping old routes working too.
*/
if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4);
    if ($uri === '') {
        $uri = '/';
    }
}

if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

if ($method === 'GET' && $uri === '/') {
    Response::json(200, [
        'service' => 'Battleship API',
        'status' => 'running'
    ]);
}

if ($method === 'GET' && $uri === '/health') {
    Response::json(200, ['status' => 'ok']);
}

/*
Production endpoints
*/
if ($method === 'POST' && $uri === '/reset') {
    $controller->resetSystem();
}

if ($method === 'POST' && $uri === '/players') {
    $controller->createPlayer();
}

if ($method === 'GET' && preg_match('#^/players/([a-zA-Z0-9\-]+)/stats$#', $uri, $matches)) {
    $controller->getPlayerStats($matches[1]);
}

if ($method === 'POST' && $uri === '/games') {
    $controller->createGame();
}

if ($method === 'POST' && preg_match('#^/games/([a-zA-Z0-9\-]+)/join$#', $uri, $matches)) {
    $controller->joinGame($matches[1]);
}

if ($method === 'GET' && preg_match('#^/games/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
    $controller->getGame($matches[1]);
}

/*
Test endpoints from official spec
*/
if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/restart$#', $uri, $matches)) {
    $testController->restartGame($matches[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/ships$#', $uri, $matches)) {
    $testController->placeShips($matches[1]);
}

if ($method === 'GET' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/board/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
    $testController->revealBoard($matches[1], $matches[2]);
}

/*
Legacy appendix endpoints kept for safety
*/
if ($method === 'GET' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/board$#', $uri, $matches)) {
    $testController->revealBoard($matches[1], null);
}

if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/reset$#', $uri, $matches)) {
    $testController->resetGame($matches[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/set-turn$#', $uri, $matches)) {
    $testController->setTurn($matches[1]);
}

Response::error(404, 'Endpoint not found.');
