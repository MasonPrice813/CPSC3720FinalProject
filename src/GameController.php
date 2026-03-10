<?php

class GameController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createGame(): void
    {
        $body = Utils::getJsonBody();

        $gridSize = $body['gridSize'] ?? null;
        $maxPlayers = $body['maxPlayers'] ?? null;

        if (!is_int($gridSize) || !is_int($maxPlayers)) {
            Response::error(400, 'gridSize and maxPlayers must be integers.');
        }

        if ($gridSize < 5 || $gridSize > 20) {
            Response::error(400, 'gridSize must be between 5 and 20.');
        }

        if ($maxPlayers < 2 || $maxPlayers > 8) {
            Response::error(400, 'maxPlayers must be between 2 and 8.');
        }

        $gameId = Utils::generateUuid();

        $stmt = $this->pdo->prepare("
            INSERT INTO games (id, status, grid_size, max_players)
            VALUES (:id, 'waiting', :grid_size, :max_players)
        ");

        $stmt->execute([
            ':id' => $gameId,
            ':grid_size' => $gridSize,
            ':max_players' => $maxPlayers
        ]);

        Response::json(201, [
            'gameId' => $gameId,
            'status' => 'waiting',
            'gridSize' => $gridSize,
            'maxPlayers' => $maxPlayers
        ]);
    }

    public function joinGame(string $gameId): void
    {
        $body = Utils::getJsonBody();
        $name = Utils::normalizeName($body['name'] ?? '');

        if ($name === '') {
            Response::error(400, 'Player name is required.');
        }

        if (mb_strlen($name) > 50) {
            Response::error(400, 'Player name must be 50 characters or fewer.');
        }

        $gameStmt = $this->pdo->prepare("
            SELECT id, status, max_players
            FROM games
            WHERE id = :id
        ");
        $gameStmt->execute([':id' => $gameId]);
        $game = $gameStmt->fetch();

        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        if ($game['status'] !== 'waiting') {
            Response::error(409, 'Game is no longer accepting players.');
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS player_count
            FROM players
            WHERE game_id = :game_id
        ");
        $countStmt->execute([':game_id' => $gameId]);
        $playerCount = (int)$countStmt->fetch()['player_count'];

        if ($playerCount >= (int)$game['max_players']) {
            Response::error(409, 'Game is already full.');
        }

        $dupStmt = $this->pdo->prepare("
            SELECT id
            FROM players
            WHERE game_id = :game_id AND LOWER(name) = LOWER(:name)
        ");
        $dupStmt->execute([
            ':game_id' => $gameId,
            ':name' => $name
        ]);

        if ($dupStmt->fetch()) {
            Response::error(409, 'Duplicate player name in this game.');
        }

        $playerId = Utils::generateUuid();

        $insertStmt = $this->pdo->prepare("
            INSERT INTO players (id, game_id, name)
            VALUES (:id, :game_id, :name)
        ");
        $insertStmt->execute([
            ':id' => $playerId,
            ':game_id' => $gameId,
            ':name' => $name
        ]);

        Response::json(201, [
            'playerId' => $playerId,
            'gameId' => $gameId,
            'name' => $name,
            'status' => 'joined'
        ]);
    }

    public function getGame(string $gameId): void
    {
        $gameStmt = $this->pdo->prepare("
            SELECT id, status, grid_size, max_players, created_at
            FROM games
            WHERE id = :id
        ");
        $gameStmt->execute([':id' => $gameId]);
        $game = $gameStmt->fetch();

        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        $playersStmt = $this->pdo->prepare("
            SELECT id, name, created_at
            FROM players
            WHERE game_id = :game_id
            ORDER BY created_at ASC
        ");
        $playersStmt->execute([':game_id' => $gameId]);
        $players = $playersStmt->fetchAll();

        Response::json(200, [
            'gameId' => $game['id'],
            'status' => $game['status'],
            'gridSize' => (int)$game['grid_size'],
            'maxPlayers' => (int)$game['max_players'],
            'players' => $players
        ]);
    }
}