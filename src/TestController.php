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

    public function restartGame(int $gameId): void
    {
        $this->requireTestPassword();

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM games
                WHERE game_id = :game_id
                FOR UPDATE
            ");
            $stmt->execute([
                ":game_id" => $gameId
            ]);

            if (!$stmt->fetch()) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
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
                SET status = 'waiting',
                    current_turn_index = 0,
                    winner_id = NULL
                WHERE game_id = :game_id
            ")->execute([
                ":game_id" => $gameId
            ]);

            $this->pdo->commit();

            Response::json(200, [
                "status" => "reset"
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

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

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT grid_size, status
                FROM games
                WHERE game_id = :game_id
                FOR UPDATE
            ");
            $stmt->execute([
                ":game_id" => $gameId
            ]);

            $game = $stmt->fetch();

            if (!$game) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(404, "Game not found.");
            }

            if ($game["status"] !== "waiting") {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Ships can only be placed before the game starts.");
            }

            $membershipStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM game_players
                WHERE game_id = :game_id
                  AND player_id = :player_id
            ");
            $membershipStmt->execute([
                ":game_id" => $gameId,
                ":player_id" => $playerId
            ]);

            if ((int)$membershipStmt->fetchColumn() === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Player is not in this game.");
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
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Player has already placed ships.");
            }

            $coordinatesToInsert = [];

            foreach ($ships as $shipIndex => $ship) {
                if (!is_array($ship)) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Response::error(400, "Invalid ship format.");
                }

                if (array_key_exists("row", $ship) && array_key_exists("col", $ship)) {
                    $coordinatesToInsert[] = [
                        "row" => (int)$ship["row"],
                        "col" => (int)$ship["col"],
                        "ship_index" => 0
                    ];
                    continue;
                }

                if (isset($ship["coordinates"]) && is_array($ship["coordinates"])) {
                    $coords = $ship["coordinates"];

                    if (count($coords) === 0) {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        Response::error(400, "Invalid ship format.");
                    }

                    foreach ($coords as $coordinate) {
                        if (
                            !is_array($coordinate) ||
                            count($coordinate) !== 2 ||
                            !is_numeric($coordinate[0]) ||
                            !is_numeric($coordinate[1])
                        ) {
                            if ($this->pdo->inTransaction()) {
                                $this->pdo->rollBack();
                            }
                            Response::error(400, "Invalid ship format.");
                        }

                        $coordinatesToInsert[] = [
                            "row" => (int)$coordinate[0],
                            "col" => (int)$coordinate[1],
                            "ship_index" => $shipIndex
                        ];
                    }

                    continue;
                }

                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Invalid ship format.");
            }

            if (count($coordinatesToInsert) === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "No ship coordinates provided.");
            }

            $seen = [];

            foreach ($coordinatesToInsert as $coordinate) {
                $row = $coordinate["row"];
                $col = $coordinate["col"];

                if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Response::error(400, "Ship out of bounds.");
                }

                $key = $row . "," . $col;
                if (isset($seen[$key])) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
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
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
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

            $playerCountStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM game_players
                WHERE game_id = :game_id
            ");
            $playerCountStmt->execute([
                ":game_id" => $gameId
            ]);
            $playerCount = (int)$playerCountStmt->fetchColumn();

            $placedCountStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM (
                    SELECT gp.player_id
                    FROM game_players gp
                    LEFT JOIN ships s
                        ON s.game_id = gp.game_id
                       AND s.player_id = gp.player_id
                    WHERE gp.game_id = :game_id
                    GROUP BY gp.player_id
                    HAVING COUNT(s.row_idx) >= 3
                ) placed_players
            ");
            $placedCountStmt->execute([
                ":game_id" => $gameId
            ]);
            $placedCount = (int)$placedCountStmt->fetchColumn();

            if ($playerCount > 0 && $placedCount === $playerCount) {
                $this->pdo->prepare("
                    UPDATE games
                    SET status = 'active',
                        current_turn_index = 0
                    WHERE game_id = :game_id
                ")->execute([
                    ":game_id" => $gameId
                ]);
            }

            $this->pdo->commit();

            Response::json(200, [
                "status" => "ships placed",
                "game_id" => $gameId,
                "gameId" => $gameId,
                "player_id" => $playerId,
                "playerId" => $playerId
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

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

        $gameStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM games
            WHERE game_id = :game_id
        ");
        $gameStmt->execute([
            ":game_id" => $gameId
        ]);

        if ((int)$gameStmt->fetchColumn() === 0) {
            Response::error(404, "Game not found.");
        }

        $playerStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM game_players
            WHERE game_id = :game_id
              AND player_id = :player_id
        ");
        $playerStmt->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        if ((int)$playerStmt->fetchColumn() === 0) {
            Response::error(404, "Player not found in game.");
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
        $shipPositions = [];

        foreach ($rows as $r) {
            $rIdx = (int)$r["row_idx"];
            $cIdx = (int)$r["col_idx"];

            $ships[] = [
                "row" => $rIdx,
                "col" => $cIdx
            ];

            $shipPositions[] = [$rIdx, $cIdx];
        }

        Response::json(200, [
            "game_id" => $gameId,
            "gameId" => $gameId,
            "player_id" => $playerId,
            "playerId" => $playerId,
            "ships" => $ships,
            "ship_positions" => $shipPositions,
            "hits" => [],
            "misses" => [],
            "ship_status" => [
                [
                    "type" => "unknown",
                    "coordinates" => $shipPositions,
                    "sunk" => false
                ]
            ]
        ]);
    }

    public function resetGame(int $gameId): void
    {
        $this->restartGame($gameId);
    }

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
            "player_id" => $playerId,
            "playerId" => $playerId
        ]);
    }
}