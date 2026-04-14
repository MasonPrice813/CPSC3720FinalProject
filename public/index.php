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
    Response::error(500, 'internal_error');
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';


if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4) ?: '/';
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

if ($method === 'POST' && $uri === '/reset') {
    $controller->resetSystem();
}

if ($method === 'GET' && $uri === '/players') {
    $controller->listPlayers();
}

if ($method === 'POST' && $uri === '/players') {
    $controller->createPlayer();
}

if ($method === 'GET' && preg_match('#^/players/([0-9]+)/stats$#', $uri, $matches)) {
    $controller->getPlayerStats((int)$matches[1]);
}

if ($method === 'GET' && $uri === '/games') {
    $controller->listGames();
}

if ($method === 'POST' && $uri === '/games') {
    $controller->createGame();
}

if ($method === 'POST' && preg_match('#^/games/([0-9]+)/join$#', $uri, $matches)) {
    $controller->joinGame((int)$matches[1]);
}

if ($method === 'GET' && preg_match('#^/games/([0-9]+)$#', $uri, $matches)) {
    $controller->getGame((int)$matches[1]);
}

if ($method === 'POST' && preg_match('#^/games/([0-9]+)/place$#', $uri, $matches)) {
    $controller->placeShips((int)$matches[1]);
}

if ($method === 'POST' && preg_match('#^/games/([0-9]+)/fire$#', $uri, $matches)) {
    $controller->fire((int)$matches[1]);
}

if ($method === 'GET' && preg_match('#^/games/([0-9]+)/moves$#', $uri, $matches)) {
    $controller->getMoves((int)$matches[1]);
}

if (str_starts_with($uri, '/test/')) {
    TestMode::requireTestPassword();
}

if ($method === 'POST' && preg_match('#^/test/games/([0-9]+)/restart$#', $uri, $matches)) {
    $testController->restartGame((int)$matches[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([0-9]+)/ships$#', $uri, $matches)) {
    $testController->placeShips((int)$matches[1]);
}

if ($method === 'GET' && preg_match('#^/test/games/([0-9]+)/board/([0-9]+)$#', $uri, $matches)) {
    $testController->revealBoard((int)$matches[1], (int)$matches[2]);
}

if ($method === 'GET' && preg_match('#^/test/games/([0-9]+)/board$#', $uri, $matches)) {
    $testController->revealBoard((int)$matches[1], null);
}

if ($method === 'POST' && preg_match('#^/test/games/([0-9]+)/reset$#', $uri, $matches)) {
    $testController->resetGame((int)$matches[1]);
}

if ($method === 'POST' && preg_match('#^/test/games/([0-9]+)/set-turn$#', $uri, $matches)) {
    $testController->setTurn((int)$matches[1]);
}

Response::error(404, 'not_found');
