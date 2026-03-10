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
        TestMode::requireTestMode();
    }

    /*
    Restart Game (Test Mode)
    POST /api/test/games/{id}/restart
    */
    public function restartGame(int $gameId): void
    {
        $this->requireTestPassword();

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");
        $stmt->execute([
            ":game_id" => $gameId
        ]);

        if (!$stmt->fetch()) {
            Response::error(404, "Game not found.");
        }

        $this->pdo->prepare("
            DELETE FROM ships
            WHERE game_id = :game_id
        ")->execute([
            ":game_id" => $gameId
        ]);

        $this->pdo->prepare("
            DELETE FROM moves
            WHERE game_id = :game_id
        ")->execute([
            ":game_id" => $gameId
        ]);

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

    Accepts both:
    1) Legacy format:
       {
         "player_id": 1,
         "ships": [
           { "row": 0, "col": 0 },
           { "row": 0, "col": 1 }
         ]
       }

    2) Official appendix / grader format:
       {
         "playerId": "123",
         "ships": [
           {
             "type": "destroyer",
             "coordinates": [[0,0],[0,1]]
           }
         ]
       }
    */
    public function placeShips(int $gameId): void
    {
        $this->requireTestPassword();

        $body = Utils::getJsonBody();

        $playerIdRaw = $body["player_id"] ?? $body["playerId"] ?? null;
        $ships = $body["ships"] ?? null;

        if ($playerIdRaw === null || $ships === null) {
            Response::error(400, "Invalid request.");
        }

        $playerId = (int)$playerIdRaw;

        if (!is_array($ships)) {
            Response::error(400, "Ships must be an array.");
        }

        $stmt = $this->pdo->prepare("
            SELECT grid_size, status
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

        if ($game["status"] !== "waiting") {
            Response::error(400, "Ships can only be placed before the game starts.");
        }

        $gridSize = (int)$game["grid_size"];

        $check = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ships
            WHERE game_id = :game_id
              AND player_id = :player_id
        ");
        $check->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        if ((int)$check->fetchColumn() > 0) {
            Response::error(400, "Player has already placed ships.");
        }

        $coordinatesToInsert = [];

        foreach ($ships as $ship) {
            if (!is_array($ship)) {
                Response::error(400, "Invalid ship format.");
            }

            /*
            Legacy flat format:
            { "row": 0, "col": 1 }
            */
            if (array_key_exists("row", $ship) && array_key_exists("col", $ship)) {
                $coordinatesToInsert[] = [
                    "row" => (int)$ship["row"],
                    "col" => (int)$ship["col"]
                ];
                continue;
            }

            /*
            Official grader format:
            {
              "type": "destroyer",
              "coordinates": [[0,0],[0,1]]
            }
            */
            if (isset($ship["coordinates"]) && is_array($ship["coordinates"])) {
                foreach ($ship["coordinates"] as $coordinate) {
                    if (
                        !is_array($coordinate) ||
                        count($coordinate) !== 2 ||
                        !is_numeric($coordinate[0]) ||
                        !is_numeric($coordinate[1])
                    ) {
                        Response::error(400, "Invalid ship format.");
                    }

                    $coordinatesToInsert[] = [
                        "row" => (int)$coordinate[0],
                        "col" => (int)$coordinate[1]
                    ];
                }
                continue;
            }

            Response::error(400, "Invalid ship format.");
        }

        if (count($coordinatesToInsert) === 0) {
            Response::error(400, "No ship coordinates provided.");
        }

        $seen = [];

        foreach ($coordinatesToInsert as $coordinate) {
            $row = $coordinate["row"];
            $col = $coordinate["col"];

            if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                Response::error(400, "Ship out of bounds.");
            }

            $key = $row . "," . $col;
            if (isset($seen[$key])) {
                Response::error(400, "Ship overlap.");
            }
            $seen[$key] = true;

            $overlap = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM ships
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
        }

        $insert = $this->pdo->prepare("
            INSERT INTO ships (game_id, player_id, row_idx, col_idx)
            VALUES (:game_id, :player_id, :row, :col)
        ");

        foreach ($coordinatesToInsert as $coordinate) {
            $insert->execute([
                ":game_id" => $gameId,
                ":player_id" => $playerId,
                ":row" => $coordinate["row"],
                ":col" => $coordinate["col"]
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
    public function revealBoard(int $gameId, ?int $playerId): void
    {
        $this->requireTestPassword();

        if ($playerId === null) {
            $playerIdRaw = $_GET["playerId"] ?? $_GET["player_id"] ?? null;

            if ($playerIdRaw === null) {
                Response::error(400, "playerId is required.");
            }

            $playerId = (int)$playerIdRaw;
        }

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

    /*
    Official appendix reset endpoint
    POST /api/test/games/{id}/reset
    */
    public function resetGame(int $gameId): void
    {
        $this->restartGame($gameId);
    }

    /*
    Optional deterministic turn setter
    POST /api/test/games/{id}/set-turn
    */
    public function setTurn(int $gameId): void
    {
        $this->requireTestPassword();

        $body = Utils::getJsonBody();
        $playerIdRaw = $body["playerId"] ?? $body["player_id"] ?? null;

        if ($playerIdRaw === null) {
            Response::error(400, "playerId is required.");
        }

        $playerId = (int)$playerIdRaw;

        $stmt = $this->pdo->prepare("
            SELECT turn_order
            FROM game_players
            WHERE game_id = :game_id
              AND player_id = :player_id
        ");
        $stmt->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            Response::error(404, "Player not found in game.");
        }

        $turnOrder = (int)$row["turn_order"];

        $update = $this->pdo->prepare("
            UPDATE games
            SET current_turn_index = :turn_order
            WHERE game_id = :game_id
        ");
        $update->execute([
            ":turn_order" => $turnOrder,
            ":game_id" => $gameId
        ]);

        Response::json(200, [
            "status" => "turn set",
            "player_id" => $playerId
        ]);
    }
}