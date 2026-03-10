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

// Remove trailing slash except root
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

/*
|--------------------------------------------------------------------------
| Root Route
|--------------------------------------------------------------------------
*/
if ($method === 'GET' && $uri === '/') {
    Response::json(200, [
        'service' => 'Battleship API',
        'status' => 'running'
    ]);
}

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
if ($method === 'GET' && $uri === '/health') {
    Response::json(200, ['status' => 'ok']);
}

/*
|--------------------------------------------------------------------------
| Create Game
|--------------------------------------------------------------------------
*/
if ($method === 'POST' && $uri === '/games') {
    $controller->createGame();
}

/*
|--------------------------------------------------------------------------
| Join Game
|--------------------------------------------------------------------------
*/
if ($method === 'POST' && preg_match('#^/games/([a-zA-Z0-9\-]+)/join$#', $uri, $matches)) {
    $controller->joinGame($matches[1]);
}

/*
|--------------------------------------------------------------------------
| Get Game State
|--------------------------------------------------------------------------
*/
if ($method === 'GET' && preg_match('#^/games/([a-zA-Z0-9\-]+)$#', $uri, $matches)) {
    $controller->getGame($matches[1]);
}

/*
|--------------------------------------------------------------------------
| TEST MODE ENDPOINTS
|--------------------------------------------------------------------------
*/

/*
Deterministic Ship Placement
POST /test/games/{gameId}/ships
*/
if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/ships$#', $uri, $matches)) {
    $testController->placeShips($matches[1]);
}

/*
Reveal Board State
GET /test/games/{gameId}/board?playerId=...
*/
if ($method === 'GET' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/board$#', $uri, $matches)) {
    $testController->revealBoard($matches[1]);
}

/*
Reset Game State
POST /test/games/{gameId}/reset
*/
if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/reset$#', $uri, $matches)) {
    $testController->resetGame($matches[1]);
}

/*
Force Turn
POST /test/games/{gameId}/set-turn
*/
if ($method === 'POST' && preg_match('#^/test/games/([a-zA-Z0-9\-]+)/set-turn$#', $uri, $matches)) {
    $testController->setTurn($matches[1]);
}

/*
|--------------------------------------------------------------------------
| Default
|--------------------------------------------------------------------------
*/
Response::error(404, 'Endpoint not found.');
