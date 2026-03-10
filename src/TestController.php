<?php

class TestController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function restartGame(string $gameId): void
    {
        TestMode::requireTestMode();

        $game = $this->findGame($gameId);
        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        $this->pdo->prepare("DELETE FROM ships WHERE game_id = :game_id")
            ->execute([':game_id' => $gameId]);

        $this->pdo->prepare("DELETE FROM moves WHERE game_id = :game_id")
            ->execute([':game_id' => $gameId]);

        $this->pdo->prepare("
            UPDATE games
            SET status = 'waiting',
                current_turn_index = 0,
                current_turn_player_id = (
                    SELECT gp.player_id
                    FROM game_players gp
                    WHERE gp.game_id = :game_id
                    ORDER BY gp.turn_order ASC
                    LIMIT 1
                )
            WHERE game_id = :game_id
        ")->execute([':game_id' => $gameId]);

        Response::json(200, [
            'status' => 'reset'
        ]);
    }

    public function resetGame(string $gameId): void
    {
        $this->restartGame($gameId);
    }

    public function placeShips(string $gameId): void
    {
        TestMode::requireTestMode();

        $game = $this->findGame($gameId);
        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        if ($game['status'] !== 'waiting') {
            Response::error(400, 'Ships may only be placed while game is waiting.');
        }

        $body = Utils::getJsonBody();
        $playerId = $body['player_id'] ?? $body['playerId'] ?? null;
        $ships = $body['ships'] ?? null;

        if (!$playerId || !is_array($ships)) {
            Response::error(400, 'Invalid request.');
        }

        if (!$this->playerInGame($gameId, $playerId)) {
            Response::error(403, 'Player is not in this game.');
        }

        $alreadyPlacedStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM ships
            WHERE game_id = :game_id AND player_id = :player_id
        ");
        $alreadyPlacedStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);
        if ((int)$alreadyPlacedStmt->fetch()['cnt'] > 0) {
            Response::error(400, 'Player has already placed ships.');
        }

        $gridSize = (int)$game['grid_size'];
        $occupied = [];
        $cellsToInsert = [];

        foreach ($ships as $shipIndex => $ship) {
            if (isset($ship['row'], $ship['col'])) {
                $row = $ship['row'];
                $col = $ship['col'];

                if (!is_int($row) || !is_int($col)) {
                    Response::error(400, 'Invalid ship coordinates.');
                }

                $this->validateBounds($row, $col, $gridSize);

                $key = $row . ':' . $col;
                if (isset($occupied[$key])) {
                    Response::error(400, 'Ship overlap detected.');
                }

                $occupied[$key] = true;
                $cellsToInsert[] = [
                    'ship_type' => 'single',
                    'row_idx' => $row,
                    'col_idx' => $col
                ];
                continue;
            }

            $type = $ship['type'] ?? ('ship_' . $shipIndex);
            $coords = $ship['coordinates'] ?? null;

            if (!is_array($coords) || count($coords) === 0) {
                Response::error(400, 'Invalid ship definition.');
            }

            foreach ($coords as $coord) {
                if (!is_array($coord) || count($coord) !== 2) {
                    Response::error(400, 'Invalid ship coordinates.');
                }

                $row = $coord[0];
                $col = $coord[1];

                if (!is_int($row) || !is_int($col)) {
                    Response::error(400, 'Invalid ship coordinates.');
                }

                $this->validateBounds($row, $col, $gridSize);

                $key = $row . ':' . $col;
                if (isset($occupied[$key])) {
                    Response::error(400, 'Ship overlap detected.');
                }

                $occupied[$key] = true;
                $cellsToInsert[] = [
                    'ship_type' => (string)$type,
                    'row_idx' => $row,
                    'col_idx' => $col
                ];
            }
        }

        $insertStmt = $this->pdo->prepare("
            INSERT INTO ships (game_id, player_id, ship_type, row_idx, col_idx)
            VALUES (:game_id, :player_id, :ship_type, :row_idx, :col_idx)
        ");

        foreach ($cellsToInsert as $cell) {
            $insertStmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':ship_type' => $cell['ship_type'],
                ':row_idx' => $cell['row_idx'],
                ':col_idx' => $cell['col_idx']
            ]);
        }

        Response::json(200, [
            'status' => 'ships placed'
        ]);
    }

    public function revealBoard(string $gameId, ?string $playerIdFromPath = null): void
    {
        TestMode::requireTestMode();

        $game = $this->findGame($gameId);
        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        $playerId = $playerIdFromPath
            ?? ($_GET['player_id'] ?? $_GET['playerId'] ?? $_GET['playerid'] ?? null);

        if (!$playerId) {
            Response::error(400, 'player_id required.');
        }

        $shipsStmt = $this->pdo->prepare("
            SELECT ship_type, row_idx, col_idx
            FROM ships
            WHERE game_id = :game_id AND player_id = :player_id
            ORDER BY row_idx, col_idx
        ");
        $shipsStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);
        $shipRows = $shipsStmt->fetchAll();

        $movesStmt = $this->pdo->prepare("
            SELECT player_id, row_idx, col_idx, result
            FROM moves
            WHERE game_id = :game_id
            ORDER BY created_at ASC, id ASC
        ");
        $movesStmt->execute([
            ':game_id' => $gameId
        ]);
        $moves = $movesStmt->fetchAll();

        $ships = [];
        foreach ($shipRows as $row) {
            $ships[] = [
                'type' => $row['ship_type'],
                'row' => (int)$row['row_idx'],
                'col' => (int)$row['col_idx']
            ];
        }

        Response::json(200, [
            'game_id' => $gameId,
            'gameId' => $gameId,
            'player_id' => $playerId,
            'playerId' => $playerId,
            'ships' => $ships,
            'hits' => array_values(array_filter($moves, fn($m) => $m['result'] === 'hit')),
            'misses' => array_values(array_filter($moves, fn($m) => $m['result'] === 'miss')),
            'sunk_status' => []
        ]);
    }

    public function setTurn(string $gameId): void
    {
        TestMode::requireTestMode();

        $body = Utils::getJsonBody();
        $playerId = $body['player_id'] ?? $body['playerId'] ?? null;

        if (!$playerId) {
            Response::error(400, 'player_id required.');
        }

        $stmt = $this->pdo->prepare("
            SELECT turn_order
            FROM game_players
            WHERE game_id = :game_id AND player_id = :player_id
        ");
        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error(403, 'Player is not in this game.');
        }

        $updateStmt = $this->pdo->prepare("
            UPDATE games
            SET current_turn_index = :turn_order,
                current_turn_player_id = :player_id
            WHERE game_id = :game_id
        ");
        $updateStmt->execute([
            ':turn_order' => (int)$row['turn_order'],
            ':player_id' => $playerId,
            ':game_id' => $gameId
        ]);

        Response::json(200, [
            'status' => 'turn updated'
        ]);
    }

    private function validateBounds(int $row, int $col, int $gridSize): void
    {
        if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
            Response::error(400, 'Ship coordinates out of bounds.');
        }
    }

    private function findGame(string $gameId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");
        $stmt->execute([':game_id' => $gameId]);
        return $stmt->fetch();
    }

    private function playerInGame(string $gameId, string $playerId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM game_players
            WHERE game_id = :game_id AND player_id = :player_id
        ");
        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);
        return (bool)$stmt->fetchColumn();
    }
}
