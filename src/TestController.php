<?php

class TestController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /*
    Deterministic ship placement
    */
    public function placeShips(string $gameId): void
    {
        TestMode::requireTestMode();

        $body = Utils::getJsonBody();
        $playerId = $body['playerId'] ?? null;
        $ships = $body['ships'] ?? [];

        if (!$playerId || !is_array($ships)) {
            Response::error(400, 'Invalid request.');
        }

        foreach ($ships as $ship) {
            $type = $ship['type'] ?? null;
            $coords = $ship['coordinates'] ?? null;

            if (!$type || !is_array($coords)) {
                Response::error(400, 'Invalid ship definition.');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO ships (game_id, player_id, type, coordinates)
                VALUES (:game_id, :player_id, :type, :coordinates)
            ");

            $stmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':type' => $type,
                ':coordinates' => json_encode($coords)
            ]);
        }

        Response::json(200, ['status' => 'ships placed']);
    }

    /*
    Reveal board state
    */
    public function revealBoard(string $gameId): void
    {
        TestMode::requireTestMode();

        $playerId = $_GET['playerId'] ?? null;

        if (!$playerId) {
            Response::error(400, 'playerId required.');
        }

        $stmt = $this->pdo->prepare("
            SELECT type, coordinates
            FROM ships
            WHERE game_id = :game_id AND player_id = :player_id
        ");

        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);

        $ships = $stmt->fetchAll();

        Response::json(200, [
            'gameId' => $gameId,
            'playerId' => $playerId,
            'ships' => $ships
        ]);
    }

    /*
    Reset game state
    */
    public function resetGame(string $gameId): void
    {
        TestMode::requireTestMode();

        $this->pdo->prepare("DELETE FROM ships WHERE game_id = :game_id")
            ->execute([':game_id' => $gameId]);

        $this->pdo->prepare("UPDATE games SET status = 'waiting' WHERE id = :id")
            ->execute([':id' => $gameId]);

        Response::json(200, ['status' => 'game reset']);
    }

    /*
    Force turn
    */
    public function setTurn(string $gameId): void
    {
        TestMode::requireTestMode();

        $body = Utils::getJsonBody();
        $playerId = $body['playerId'] ?? null;

        if (!$playerId) {
            Response::error(400, 'playerId required.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE games SET current_turn = :player_id
            WHERE id = :game_id
        ");

        $stmt->execute([
            ':player_id' => $playerId,
            ':game_id' => $gameId
        ]);

        Response::json(200, ['status' => 'turn updated']);
    }
}
