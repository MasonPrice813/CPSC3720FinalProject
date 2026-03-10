<?php

class GameController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function resetSystem(): void
    {
        $this->pdo->exec("DELETE FROM moves");
        $this->pdo->exec("DELETE FROM ships");
        $this->pdo->exec("DELETE FROM game_players");
        $this->pdo->exec("DELETE FROM games");
        $this->pdo->exec("DELETE FROM players");

        Response::json(200, ["status" => "reset"]);
    }

    public function createPlayer(): void
    {
        $body = Utils::getJsonBody();

        if (!isset($body["username"]) || trim($body["username"]) === "") {
            Response::error(400, "Username is required.");
        }

        $displayName = trim($body["username"]);

        $stmt = $this->pdo->prepare("
            INSERT INTO players (display_name)
            VALUES (:display_name)
            RETURNING player_id
        ");

        $stmt->execute([
            ":display_name" => $displayName
        ]);

        $playerId = (int)$stmt->fetchColumn();

        Response::json(201, [
            "player_id" => $playerId,
            "username" => $displayName,
            "wins" => 0,
            "losses" => 0
        ]);
    }

    public function getPlayerStats(int $playerId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM players
            WHERE player_id = :player_id
        ");

        $stmt->execute([
            ":player_id" => $playerId
        ]);

        $player = $stmt->fetch();

        if (!$player) {
            Response::error(404, "Player not found.");
        }

        $shots = (int)$player["total_shots"];
        $hits = (int)$player["total_hits"];

        $accuracy = $shots > 0 ? $hits / $shots : 0;

        Response::json(200, [
            "games_played" => (int)$player["total_games"],
            "wins" => (int)$player["total_wins"],
            "losses" => (int)$player["total_losses"],
            "total_shots" => $shots,
            "total_hits" => $hits,
            "accuracy" => $accuracy
        ]);
    }

    public function createGame(): void
    {
        $body = Utils::getJsonBody();

        $gridSize = $body["grid_size"] ?? null;
        $maxPlayers = $body["max_players"] ?? null;

        if (!is_int($gridSize) || !is_int($maxPlayers)) {
            Response::error(400, "grid_size and max_players must be integers.");
        }

        if ($gridSize < 5 || $gridSize > 15) {
            Response::error(400, "grid_size must be between 5 and 15.");
        }

        if ($maxPlayers < 1) {
            Response::error(400, "max_players must be at least 1.");
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO games (grid_size, max_players)
            VALUES (:grid_size, :max_players)
            RETURNING game_id
        ");

        $stmt->execute([
            ":grid_size" => $gridSize,
            ":max_players" => $maxPlayers
        ]);

        $gameId = (int)$stmt->fetchColumn();

        Response::json(201, [
            "game_id" => $gameId,
            "status" => "waiting",
            "grid_size" => $gridSize,
            "max_players" => $maxPlayers
        ]);
    }

    public function joinGame(int $gameId): void
    {
        $body = Utils::getJsonBody();

        if (!isset($body["player_id"])) {
            Response::error(400, "player_id required.");
        }

        $playerId = (int)$body["player_id"];

        $gameStmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");

        $gameStmt->execute([
            ":game_id" => $gameId
        ]);

        $game = $gameStmt->fetch();

        if (!$game) {
            Response::error(404, "Game not found.");
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM game_players
            WHERE game_id = :game_id
        ");

        $countStmt->execute([
            ":game_id" => $gameId
        ]);

        $turnOrder = (int)$countStmt->fetchColumn();

        $insert = $this->pdo->prepare("
            INSERT INTO game_players (game_id, player_id, turn_order)
            VALUES (:game_id, :player_id, :turn_order)
        ");

        $insert->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId,
            ":turn_order" => $turnOrder
        ]);

        Response::json(201, [
            "player_id" => $playerId,
            "game_id" => $gameId,
            "status" => "joined"
        ]);
    }

    public function getGame(int $gameId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");

        $stmt->execute([
            ":game_id" => $gameId
        ]);

        $game = $stmt->fetch();

        if (!$game) {
            Response::error(404, "Game not found.");
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM game_players
            WHERE game_id = :game_id
        ");

        $countStmt->execute([
            ":game_id" => $gameId
        ]);

        $players = (int)$countStmt->fetchColumn();

        Response::json(200, [
            "game_id" => $gameId,
            "grid_size" => (int)$game["grid_size"],
            "status" => $game["status"],
            "current_turn_index" => (int)$game["current_turn_index"],
            "active_players" => $players
        ]);
    }
}
