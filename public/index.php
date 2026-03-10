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

if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

/*
Root route
*/
if ($method === 'GET' && $uri === '/') {
    Response::json(200, [
        'service' => 'Battleship API',
        'status' => 'running'
    ]);
}

/*
Health
*/
if ($method === 'GET' && $uri === '/health') {
    Response::json(200, ['status' => 'ok']);
}

/*
Create Player
*/
if ($method === 'POST' && $uri === '/players') {
    $controller->createPlayer();
}

/*
Create Game
*/
if ($method === 'POST' && $uri === '/games') {
    $controller->createGame();
}

/*
Join Game
*/
if ($method === 'POST' && preg_match('#^/games/([a-zA-Z0-9\-]+)/join$#', $uri, $matches)) {
    $controller->joinGame($matches[1]);
}

/*
Get Game
*/
if ($method === 'GET' && preg_match('#^/games/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
    $controller->getGame($matches[1]);
}

/*
Test mode endpoints
*/
if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/ships$#', $uri, $m)) {
    $testController->placeShips($m[1]);
}

if ($method === 'GET' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/board$#', $uri, $m)) {
    $testController->revealBoard($m[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/reset$#', $uri, $m)) {
    $testController->resetGame($m[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/set-turn$#', $uri, $m)) {
    $testController->setTurn($m[1]);
}

Response::error(404, 'Endpoint not found.');
