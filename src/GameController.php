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
        $this->pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
        Response::json(200, ["status" => "reset"]);
    }

    public function createPlayer(): void
    {
        $body = Utils::getJsonBody();

        if (array_key_exists("player_id", $body) || array_key_exists("playerId", $body)) {
            Response::error(400, "Client may not supply player_id.");
        }

        if (!isset($body["username"]) || trim((string)$body["username"]) === "") {
            Response::error(400, "Username is required.");
        }

        $displayName = trim((string)$body["username"]);

        try {
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
                "player_id" => $playerId
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                Response::error(400, "Username already exists.");
            }
            throw $e;
        }
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
        $accuracy = $shots > 0 ? $hits / $shots : 0.0;

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

        $creatorId = $body["creator_id"] ?? null;
        $gridSize = $body["grid_size"] ?? null;
        $maxPlayers = $body["max_players"] ?? null;

        if (!is_int($creatorId) || !is_int($gridSize) || !is_int($maxPlayers)) {
            Response::error(400, "creator_id, grid_size, and max_players must be integers.");
        }

        if ($gridSize < 5 || $gridSize > 15) {
            Response::error(400, "grid_size must be between 5 and 15.");
        }

        if ($maxPlayers < 1) {
            Response::error(400, "max_players must be at least 1.");
        }

        $this->requireExistingPlayer($creatorId);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO games (grid_size, max_players, status, current_turn_index)
                VALUES (:grid_size, :max_players, 'waiting', 0)
                RETURNING game_id
            ");
            $stmt->execute([
                ":grid_size" => $gridSize,
                ":max_players" => $maxPlayers
            ]);

            $gameId = (int)$stmt->fetchColumn();

            $joinStmt = $this->pdo->prepare("
                INSERT INTO game_players (game_id, player_id, turn_order)
                VALUES (:game_id, :player_id, 0)
            ");
            $joinStmt->execute([
                ":game_id" => $gameId,
                ":player_id" => $creatorId
            ]);

            $this->pdo->commit();

            Response::json(201, [
                "game_id" => $gameId,
                "status" => "waiting",
                "grid_size" => $gridSize,
                "max_players" => $maxPlayers
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function joinGame(int $gameId): void
    {
        $body = Utils::getJsonBody();

        if (!isset($body["player_id"]) || !is_int($body["player_id"])) {
            Response::error(400, "player_id required.");
        }

        $playerId = (int)$body["player_id"];
        $this->requireExistingPlayer($playerId);

        $this->pdo->beginTransaction();

        try {
            $gameStmt = $this->pdo->prepare("
                SELECT *
                FROM games
                WHERE game_id = :game_id
                FOR UPDATE
            ");
            $gameStmt->execute([
                ":game_id" => $gameId
            ]);
            $game = $gameStmt->fetch();

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
                Response::error(409, "Game is no longer accepting players.");
            }

            $duplicateStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM game_players
                WHERE game_id = :game_id
                  AND player_id = :player_id
            ");
            $duplicateStmt->execute([
                ":game_id" => $gameId,
                ":player_id" => $playerId
            ]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Player already joined this game.");
            }

            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM game_players
                WHERE game_id = :game_id
            ");
            $countStmt->execute([
                ":game_id" => $gameId
            ]);

            $currentPlayers = (int)$countStmt->fetchColumn();
            $maxPlayers = (int)$game["max_players"];

            if ($currentPlayers >= $maxPlayers) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(409, "Game is full.");
            }

            $insert = $this->pdo->prepare("
                INSERT INTO game_players (game_id, player_id, turn_order)
                VALUES (:game_id, :player_id, :turn_order)
            ");
            $insert->execute([
                ":game_id" => $gameId,
                ":player_id" => $playerId,
                ":turn_order" => $currentPlayers
            ]);

            $this->pdo->commit();

            Response::json(200, [
                "player_id" => $playerId,
                "game_id" => $gameId,
                "status" => "joined"
            ]);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($e->getCode() === '23505') {
                Response::error(409, "Player already joined or turn order conflict.");
            }

            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getGame(int $gameId): void
    {
        $game = $this->getGameRow($gameId);

        if (!$game) {
            Response::error(404, "Game not found.");
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM game_players
            WHERE game_id = :game_id
        ");
        $countStmt->execute([
            ":game_id" => $gameId
        ]);

        $players = (int)$countStmt->fetchColumn();

        $response = [
            "game_id" => $gameId,
            "grid_size" => (int)$game["grid_size"],
            "status" => $game["status"],
            "current_turn_index" => (int)$game["current_turn_index"],
            "active_players" => $players
        ];

        if ($game["winner_id"] !== null) {
            $response["winner_id"] = (int)$game["winner_id"];
        }

        Response::json(200, $response);
    }

    public function placeShips(int $gameId): void
    {
        $body = Utils::getJsonBody();

        if (!isset($body["player_id"]) || !is_int($body["player_id"])) {
            Response::error(400, "player_id required.");
        }

        if (!isset($body["ships"]) || !is_array($body["ships"])) {
            Response::error(400, "ships must be an array.");
        }

        $playerId = (int)$body["player_id"];
        $ships = $body["ships"];

        $this->requireExistingPlayer($playerId);

        $this->pdo->beginTransaction();

        try {
            $gameStmt = $this->pdo->prepare("
                SELECT *
                FROM games
                WHERE game_id = :game_id
                FOR UPDATE
            ");
            $gameStmt->execute([
                ":game_id" => $gameId
            ]);
            $game = $gameStmt->fetch();

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
                Response::error(409, "Ships can only be placed while the game is waiting.");
            }

            if (!$this->playerInGame($gameId, $playerId)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(403, "Player is not in this game.");
            }

            if ($this->playerAlreadyPlacedShips($gameId, $playerId)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Player has already placed ships.");
            }

            if (count($ships) !== 3) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Response::error(400, "Exactly 3 ships are required.");
            }

            $gridSize = (int)$game["grid_size"];
            $seen = [];
            $validatedShips = [];

            foreach ($ships as $ship) {
                if (
                    !is_array($ship) ||
                    !array_key_exists("row", $ship) ||
                    !array_key_exists("col", $ship) ||
                    !is_int($ship["row"]) ||
                    !is_int($ship["col"])
                ) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Response::error(400, "Each ship must include integer row and col values.");
                }

                $row = $ship["row"];
                $col = $ship["col"];

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
                    Response::error(400, "Overlapping ship coordinates are not allowed.");
                }
                $seen[$key] = true;

                $overlapStmt = $this->pdo->prepare("
                    SELECT COUNT(*)
                    FROM ships
                    WHERE game_id = :game_id
                      AND row_idx = :row_idx
                      AND col_idx = :col_idx
                ");
                $overlapStmt->execute([
                    ":game_id" => $gameId,
                    ":row_idx" => $row,
                    ":col_idx" => $col
                ]);

                if ((int)$overlapStmt->fetchColumn() > 0) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Response::error(400, "Overlapping ship coordinates are not allowed.");
                }

                $validatedShips[] = [
                    "row" => $row,
                    "col" => $col
                ];
            }

            $insertStmt = $this->pdo->prepare("
                INSERT INTO ships (game_id, player_id, row_idx, col_idx)
                VALUES (:game_id, :player_id, :row_idx, :col_idx)
            ");

            foreach ($validatedShips as $ship) {
                $insertStmt->execute([
                    ":game_id" => $gameId,
                    ":player_id" => $playerId,
                    ":row_idx" => $ship["row"],
                    ":col_idx" => $ship["col"]
                ]);
            }

            if ($this->allPlayersPlacedShips($gameId)) {
                $stmt = $this->pdo->prepare("
                    UPDATE games
                    SET status = 'active',
                        current_turn_index = 0
                    WHERE game_id = :game_id
                ");
                $stmt->execute([
                    ":game_id" => $gameId
                ]);
            }

            $this->pdo->commit();

            Response::json(200, [
                "status" => "ships placed",
                "game_id" => $gameId,
                "player_id" => $playerId
            ]);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($e->getCode() === '23505') {
                Response::error(400, "Overlapping ship coordinates are not allowed.");
            }

            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function fire(int $gameId): void
    {
        $body = Utils::getJsonBody();

        if (
            !isset($body["player_id"]) || !is_int($body["player_id"]) ||
            !isset($body["row"]) || !is_int($body["row"]) ||
            !isset($body["col"]) || !is_int($body["col"])
        ) {
            Response::error(400, "player_id, row, and col must be integers.");
        }

        $playerId = (int)$body["player_id"];
        $row = (int)$body["row"];
        $col = (int)$body["col"];

        $this->requireExistingPlayer($playerId);

        $this->pdo->beginTransaction();

        try {
            $gameStmt = $this->pdo->prepare("
                SELECT *
                FROM games
                WHERE game_id = :game_id
                FOR UPDATE
            ");
            $gameStmt->execute([
                ":game_id" => $gameId
            ]);
            $game = $gameStmt->fetch();

            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, "Game not found.");
            }

            if (!$this->playerInGame($gameId, $playerId)) {
                $this->pdo->rollBack();
                Response::error(403, "Player is not in this game.");
            }

            $gridSize = (int)$game["grid_size"];
            if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                $this->pdo->rollBack();
                Response::error(400, "Shot out of bounds.");
            }

            if (!$this->allPlayersPlacedShips($gameId)) {
                $this->pdo->rollBack();
                Response::error(409, "All players must place ships before firing.");
            }

            if ($game["status"] === "finished") {
                $this->pdo->rollBack();
                Response::error(410, "Game is already finished.");
            }

            if ($game["status"] !== "active") {
                $this->pdo->rollBack();
                Response::error(403, "Game is not active.");
            }

            $currentPlayerId = $this->getCurrentTurnPlayerId($gameId);
            if ($currentPlayerId === null || $currentPlayerId !== $playerId) {
                $this->pdo->rollBack();
                Response::error(403, "It is not this player's turn.");
            }

            // prevent duplicate shot
            $dupStmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM moves
                WHERE game_id = :game_id
                AND player_id = :player_id
                AND row_idx = :row AND col_idx = :col
            ");
            $dupStmt->execute([
                ":game_id"=>$gameId,
                ":player_id"=>$playerId,
                ":row"=>$row,
                ":col"=>$col
            ]);

            if ((int)$dupStmt->fetchColumn() > 0) {
                $this->pdo->rollBack();
                Response::error(400, "Already fired there.");
            }

            // check hit
            $hitStmt = $this->pdo->prepare("
                SELECT player_id FROM ships
                WHERE game_id = :game_id
                AND row_idx = :row
                AND col_idx = :col
                AND player_id <> :player_id
                LIMIT 1
            ");
            $hitStmt->execute([
                ":game_id"=>$gameId,
                ":row"=>$row,
                ":col"=>$col,
                ":player_id"=>$playerId
            ]);

            $result = $hitStmt->fetch() ? "hit" : "miss";

            // insert move
            $this->pdo->prepare("
                INSERT INTO moves (game_id, player_id, row_idx, col_idx, result)
                VALUES (:g,:p,:r,:c,:res)
            ")->execute([
                ":g"=>$gameId,
                ":p"=>$playerId,
                ":r"=>$row,
                ":c"=>$col,
                ":res"=>$result
            ]);

            // update stats
            $this->pdo->prepare("
                UPDATE players
                SET total_shots = total_shots + 1,
                    total_hits = total_hits + :h
                WHERE player_id = :id
            ")->execute([
                ":h"=>($result==="hit"?1:0),
                ":id"=>$playerId
            ]);

            // 🔥 NEW WIN LOGIC (MULTIPLAYER SAFE)
            $aliveStmt = $this->pdo->prepare("
                SELECT s.player_id
                FROM ships s
                WHERE s.game_id = :game_id
                AND NOT EXISTS (
                    SELECT 1 FROM moves m
                    WHERE m.game_id = s.game_id
                        AND m.row_idx = s.row_idx
                        AND m.col_idx = s.col_idx
                        AND m.result = 'hit'
                )
                GROUP BY s.player_id
            ");
            $aliveStmt->execute([":game_id"=>$gameId]);
            $alive = $aliveStmt->fetchAll();

            if (count($alive) === 1) {
                $winnerId = (int)$alive[0]["player_id"];

                $this->pdo->prepare("
                    UPDATE games
                    SET status='finished', winner_id=:w
                    WHERE game_id=:g
                ")->execute([
                    ":w"=>$winnerId,
                    ":g"=>$gameId
                ]);

                // stats update
                $players = $this->pdo->prepare("
                    SELECT player_id FROM game_players WHERE game_id=:g
                ");
                $players->execute([":g"=>$gameId]);

                foreach ($players->fetchAll() as $p) {
                    $pid = (int)$p["player_id"];

                    $this->pdo->prepare("
                        UPDATE players SET total_games = total_games + 1
                        WHERE player_id=:id
                    ")->execute([":id"=>$pid]);

                    if ($pid === $winnerId) {
                        $this->pdo->prepare("
                            UPDATE players SET total_wins = total_wins + 1
                            WHERE player_id=:id
                        ")->execute([":id"=>$pid]);
                    } else {
                        $this->pdo->prepare("
                            UPDATE players SET total_losses = total_losses + 1
                            WHERE player_id=:id
                        ")->execute([":id"=>$pid]);
                    }
                }

                $this->pdo->commit();

                Response::json(200, [
                    "result"=>$result,
                    "next_player_id"=>null,
                    "game_status"=>"finished",
                    "winner_id"=>$winnerId
                ]);
                return;
            }

            // normal turn
            $nextIndex = $this->getNextTurnIndex($gameId);

            $this->pdo->prepare("
                UPDATE games SET current_turn_index=:i WHERE game_id=:g
            ")->execute([
                ":i"=>$nextIndex,
                ":g"=>$gameId
            ]);

            $nextPlayer = $this->getPlayerIdByTurnOrder($gameId,$nextIndex);

            $this->pdo->commit();

            Response::json(200, [
                "result"=>$result,
                "next_player_id"=>$nextPlayer,
                "game_status"=>"active"
            ]);

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

        public function getMoves(int $gameId): void
        {
            $game = $this->getGameRow($gameId);

            if (!$game) {
                Response::error(404, "Game not found.");
            }

            $stmt = $this->pdo->prepare("
                SELECT move_id, player_id, row_idx, col_idx, result, created_at
                FROM moves
                WHERE game_id = :game_id
                ORDER BY created_at ASC, move_id ASC
            ");
            $stmt->execute([
                ":game_id" => $gameId
            ]);

            $moves = [];
            foreach ($stmt->fetchAll() as $row) {
                $moves[] = [
                    "move_id" => (int)$row["move_id"],
                    "player_id" => (int)$row["player_id"],
                    "row" => (int)$row["row_idx"],
                    "col" => (int)$row["col_idx"],
                    "result" => $row["result"],
                    "created_at" => $row["created_at"]
                ];
            }

            Response::json(200, [
                "game_id" => $gameId,
                "moves" => $moves
            ]);
        }

    private function requireExistingPlayer(int $playerId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM players
            WHERE player_id = :player_id
        ");
        $stmt->execute([
            ":player_id" => $playerId
        ]);

        if ((int)$stmt->fetchColumn() === 0) {
            Response::error(403, "Invalid player_id.");
        }
    }

    private function getGameRow(int $gameId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");
        $stmt->execute([
            ":game_id" => $gameId
        ]);

        return $stmt->fetch();
    }

    private function playerInGame(int $gameId, int $playerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM game_players
            WHERE game_id = :game_id
              AND player_id = :player_id
        ");
        $stmt->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function playerAlreadyPlacedShips(int $gameId, int $playerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM ships
            WHERE game_id = :game_id
              AND player_id = :player_id
        ");
        $stmt->execute([
            ":game_id" => $gameId,
            ":player_id" => $playerId
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function allPlayersPlacedShips(int $gameId): bool
    {
        $playerCountStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM game_players
            WHERE game_id = :game_id
        ");
        $playerCountStmt->execute([
            ":game_id" => $gameId
        ]);
        $playerCount = (int)$playerCountStmt->fetchColumn();

        if ($playerCount === 0) {
            return false;
        }

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
                HAVING COUNT(s.row_idx) = 3
            ) placed_players
        ");
        $placedCountStmt->execute([
            ":game_id" => $gameId
        ]);
        $placedCount = (int)$placedCountStmt->fetchColumn();

        return $placedCount === $playerCount;
    }

    private function getCurrentTurnPlayerId(int $gameId): ?int
    {
        $gameStmt = $this->pdo->prepare("
            SELECT current_turn_index
            FROM games
            WHERE game_id = :game_id
        ");
        $gameStmt->execute([
            ":game_id" => $gameId
        ]);

        $game = $gameStmt->fetch();
        if (!$game) {
            return null;
        }

        $turnIndex = (int)$game["current_turn_index"];

        $playerStmt = $this->pdo->prepare("
            SELECT player_id
            FROM game_players
            WHERE game_id = :game_id
              AND turn_order = :turn_order
            LIMIT 1
        ");
        $playerStmt->execute([
            ":game_id" => $gameId,
            ":turn_order" => $turnIndex
        ]);

        $row = $playerStmt->fetch();
        return $row ? (int)$row["player_id"] : null;
    }

    private function getNextTurnIndex(int $gameId): int
    {
        $gameStmt = $this->pdo->prepare("
            SELECT current_turn_index
            FROM games
            WHERE game_id = :game_id
        ");
        $gameStmt->execute([
            ":game_id" => $gameId
        ]);
        $game = $gameStmt->fetch();

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM game_players
            WHERE game_id = :game_id
        ");
        $countStmt->execute([
            ":game_id" => $gameId
        ]);
        $playerCount = (int)$countStmt->fetchColumn();

        if (!$game || $playerCount <= 0) {
            return 0;
        }

        $current = (int)$game["current_turn_index"];
        return ($current + 1) % $playerCount;
    }

    private function getPlayerIdByTurnOrder(int $gameId, int $turnOrder): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT player_id
            FROM game_players
            WHERE game_id = :game_id
              AND turn_order = :turn_order
            LIMIT 1
        ");
        $stmt->execute([
            ":game_id" => $gameId,
            ":turn_order" => $turnOrder
        ]);

        $row = $stmt->fetch();
        return $row ? (int)$row["player_id"] : null;
    }
}