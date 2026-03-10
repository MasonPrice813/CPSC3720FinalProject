<?php

class TestController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function requireTestPassword(): void
    {
        $headers = getallheaders();

        if (!isset($headers["X-Test-Password"]) || $headers["X-Test-Password"] !== "clemson-test-2026") {
            Response::error(403, "Forbidden");
        }
    }

    /*
    Restart Game (Test Mode)
    POST /api/test/games/{id}/restart
    */
    public function restartGame(int $gameId): void
    {
        $this->requireTestPassword();

        $stmt = $this->pdo->prepare("SELECT * FROM games WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $gameId]);

        if (!$stmt->fetch()) {
            Response::error(404, "Game not found.");
        }

        $this->pdo->prepare("DELETE FROM ships WHERE game_id = :game_id")
            ->execute([":game_id" => $gameId]);

        $this->pdo->prepare("DELETE FROM moves WHERE game_id = :game_id")
            ->execute([":game_id" => $gameId]);

        $this->pdo->prepare("
            UPDATE games
            SET status = 'waiting'
            WHERE game_id = :game_id
        ")->execute([
            ":game_id" => $gameId
        ]);

        Response::json(200, [
            "status" => "reset"
        ]);
    }

    /*
    Deterministic Ship Placement
    POST /api/test/games/{id}/ships
    */
    public function placeShips(int $gameId): void
    {
        $this->requireTestPassword();

        $body = Utils::getJsonBody();

        if (!isset($body["player_id"]) || !isset($body["ships"])) {
            Response::error(400, "Invalid request.");
        }

        $playerId = (int)$body["player_id"];
        $ships = $body["ships"];

        if (!is_array($ships)) {
            Response::error(400, "Ships must be an array.");
        }

        // Verify game exists
        $stmt = $this->pdo->prepare("SELECT grid_size FROM games WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $gameId]);
        $game = $stmt->fetch();

        if (!$game) {
            Response::error(404, "Game not found.");
        }

        $gridSize = (int)$game["grid_size"];

        // Check if ships already placed
        $check = $this->pdo->prepare("
            SELECT COUNT(*) FROM ships
            WHERE game_id = :game_id AND player_id = :player_id
        ");

        $check->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        if ((int)$check->fetchColumn() > 0) {
            Response::error(400, "Player has already placed ships.");
        }

        foreach ($ships as $ship) {

            if (!isset($ship["row"]) || !isset($ship["col"])) {
                Response::error(400, "Invalid ship format.");
            }

            $row = (int)$ship["row"];
            $col = (int)$ship["col"];

            if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                Response::error(400, "Ship out of bounds.");
            }

            // prevent overlap
            $overlap = $this->pdo->prepare("
                SELECT COUNT(*) FROM ships
                WHERE game_id = :game_id
                AND row_idx = :row
                AND col_idx = :col
            ");

            $overlap->execute([
                ":game_id" => $gameId,
                ":row" => $row,
                ":col" => $col
            ]);

            if ((int)$overlap->fetchColumn() > 0) {
                Response::error(400, "Ship overlap.");
            }

            $insert = $this->pdo->prepare("
                INSERT INTO ships (game_id, player_id, row_idx, col_idx)
                VALUES (:game_id, :player_id, :row, :col)
            ");

            $insert->execute([
                ":game_id" => $gameId,
                ":player_id" => $playerId,
                ":row" => $row,
                ":col" => $col
            ]);
        }

        Response::json(200, [
            "status" => "ships placed"
        ]);
    }

    /*
    Reveal Board State
    GET /api/test/games/{id}/board/{player_id}
    */
    public function revealBoard(int $gameId, int $playerId): void
    {
        $this->requireTestPassword();

        $stmt = $this->pdo->prepare("
            SELECT row_idx, col_idx
            FROM ships
            WHERE game_id = :game_id
            AND player_id = :player_id
            ORDER BY row_idx, col_idx
        ");

        $stmt->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        $rows = $stmt->fetchAll();

        $ships = [];

        foreach ($rows as $r) {
            $ships[] = [
                "row" => (int)$r["row_idx"],
                "col" => (int)$r["col_idx"]
            ];
        }

        Response::json(200, [
            "game_id" => $gameId,
            "player_id" => $playerId,
            "ships" => $ships
        ]);
    }
}
