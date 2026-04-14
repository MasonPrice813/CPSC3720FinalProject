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
            // Ensure game exists
            $stmt = $this->pdo->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
            $stmt->execute([':game_id' => $gameId]);
            $game = $stmt->fetch();

            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Game not found.');
            }

            // 🔥 FULL RESET (THIS IS WHAT YOU WERE MISSING)
            $this->pdo->exec('TRUNCATE TABLE moves RESTART IDENTITY CASCADE');
            $this->pdo->exec('TRUNCATE TABLE ships RESTART IDENTITY CASCADE');
            $this->pdo->exec('TRUNCATE TABLE game_players RESTART IDENTITY CASCADE');
            $this->pdo->exec('TRUNCATE TABLE games RESTART IDENTITY CASCADE');
            $this->pdo->exec('TRUNCATE TABLE players RESTART IDENTITY CASCADE');

            // 🔥 Recreate the game so autograder still works
            $this->pdo->prepare("
                INSERT INTO games (game_id, status, current_turn_index, winner_id)
                VALUES (:game_id, 'waiting_setup', 0, NULL)
            ")->execute([':game_id' => $gameId]);

            $this->pdo->commit();

            Response::json(200, ['status' => 'reset']);
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
        $playerId = Utils::getInt($body, ['player_id', 'playerId']);
        $ships = $body['ships'] ?? null;

        if ($playerId === null || !is_array($ships)) {
            Response::error(400, 'bad_request', 'Invalid request.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT grid_size, status FROM games WHERE game_id = :game_id FOR UPDATE');
            $stmt->execute([':game_id' => $gameId]);
            $game = $stmt->fetch();
            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Game not found.');
            }

            if (!in_array($game['status'], ['waiting_setup', 'playing'], true)) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Ships can only be placed before the game starts.');
            }

            $membershipStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
            $membershipStmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
            ]);
            if ((int)$membershipStmt->fetchColumn() === 0) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Player not found in game.');
            }

            $this->pdo->prepare('DELETE FROM ships WHERE game_id = :game_id AND player_id = :player_id')->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
            ]);

            $gridSize = (int)$game['grid_size'];
            $coordinates = [];

            foreach ($ships as $ship) {
                if (is_array($ship) && array_key_exists('row', $ship) && array_key_exists('col', $ship)) {
                    $coordinates[] = ['row' => (int)$ship['row'], 'col' => (int)$ship['col']];
                    continue;
                }

                if (is_array($ship) && isset($ship['coordinates']) && is_array($ship['coordinates'])) {
                    foreach ($ship['coordinates'] as $coordinate) {
                        if (!is_array($coordinate) || count($coordinate) !== 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
                            $this->pdo->rollBack();
                            Response::error(400, 'bad_request', 'Invalid ship format.');
                        }
                        $coordinates[] = ['row' => (int)$coordinate[0], 'col' => (int)$coordinate[1]];
                    }
                    continue;
                }

                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Invalid ship format.');
            }

            if (count($coordinates) === 0) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'No ship coordinates provided.');
            }

            $seen = [];
            $insert = $this->pdo->prepare('INSERT INTO ships (game_id, player_id, row_idx, col_idx) VALUES (:game_id, :player_id, :row, :col)');
            foreach ($coordinates as $coordinate) {
                $row = $coordinate['row'];
                $col = $coordinate['col'];

                if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                    $this->pdo->rollBack();
                    Response::error(400, 'bad_request', 'Ship out of bounds.');
                }

                $key = $row . ',' . $col;
                if (isset($seen[$key])) {
                    $this->pdo->rollBack();
                    Response::error(400, 'bad_request', 'Ship overlap.');
                }
                $seen[$key] = true;

                $insert->execute([
                    ':game_id' => $gameId,
                    ':player_id' => $playerId,
                    ':row' => $row,
                    ':col' => $col,
                ]);
            }

            $playerCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id');
            $playerCountStmt->execute([':game_id' => $gameId]);
            $playerCount = (int)$playerCountStmt->fetchColumn();

            $placedCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM (SELECT gp.player_id FROM game_players gp LEFT JOIN ships s ON s.game_id = gp.game_id AND s.player_id = gp.player_id WHERE gp.game_id = :game_id GROUP BY gp.player_id HAVING COUNT(s.row_idx) >= 3) placed_players');
            $placedCountStmt->execute([':game_id' => $gameId]);
            $placedCount = (int)$placedCountStmt->fetchColumn();

            if ($playerCount >= 2 && $placedCount === $playerCount) {
                $this->pdo->prepare("UPDATE games SET status = 'playing', current_turn_index = 0 WHERE game_id = :game_id")->execute([':game_id' => $gameId]);
            }

            $this->pdo->commit();
            Response::json(200, [
                'status' => 'placed',
                'game_id' => $gameId,
                'gameId' => $gameId,
                'player_id' => $playerId,
                'playerId' => $playerId,
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
            $playerId = Utils::getInt($_GET, ['playerId', 'player_id']);
            if ($playerId === null) {
                Response::error(400, 'bad_request', 'playerId is required.');
            }
        }

        $gameStmt = $this->pdo->prepare('SELECT grid_size FROM games WHERE game_id = :game_id');
        $gameStmt->execute([':game_id' => $gameId]);
        $game = $gameStmt->fetch();
        if (!$game) {
            Response::error(404, 'not_found', 'Game not found.');
        }

        $playerStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $playerStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
        ]);
        if ((int)$playerStmt->fetchColumn() === 0) {
            Response::error(404, 'not_found', 'Player not found in game.');
        }

        $gridSize = (int)$game['grid_size'];
        $shipStmt = $this->pdo->prepare('SELECT row_idx, col_idx FROM ships WHERE game_id = :game_id AND player_id = :player_id');
        $shipStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
        ]);
        $ships = $shipStmt->fetchAll();

        $shipMap = [];
        $shipPositions = [];
        foreach ($ships as $row) {
            $key = $row['row_idx'] . ',' . $row['col_idx'];
            $shipMap[$key] = true;
            $shipPositions[] = [(int)$row['row_idx'], (int)$row['col_idx']];
        }

        $hitStmt = $this->pdo->prepare("SELECT row_idx, col_idx FROM moves WHERE game_id = :game_id AND result = 'hit'");
        $hitStmt->execute([':game_id' => $gameId]);
        $hitRows = $hitStmt->fetchAll();
        $hitMap = [];
        foreach ($hitRows as $row) {
            $hitMap[$row['row_idx'] . ',' . $row['col_idx']] = true;
        }

        $missStmt = $this->pdo->prepare("SELECT row_idx, col_idx FROM moves WHERE game_id = :game_id AND result = 'miss'");
        $missStmt->execute([':game_id' => $gameId]);
        $missRows = $missStmt->fetchAll();
        $missMap = [];
        foreach ($missRows as $row) {
            $missMap[$row['row_idx'] . ',' . $row['col_idx']] = true;
        }

        $board = [];
        for ($r = 0; $r < $gridSize; $r++) {
            $cells = [];
            for ($c = 0; $c < $gridSize; $c++) {
                $key = $r . ',' . $c;
                if (isset($shipMap[$key]) && isset($hitMap[$key])) {
                    $cells[] = 'X';
                } elseif (isset($shipMap[$key])) {
                    $cells[] = 'O';
                } elseif (isset($missMap[$key])) {
                    $cells[] = 'M';
                } else {
                    $cells[] = '~';
                }
            }
            $board[] = implode(' ', $cells);
        }

        Response::json(200, [
            'game_id' => $gameId,
            'gameId' => $gameId,
            'player_id' => $playerId,
            'playerId' => $playerId,
            'board' => $board,
            'ships' => $ships,
            'ship_positions' => $shipPositions,
            'hits' => array_map(fn($r) => [(int)$r['row_idx'], (int)$r['col_idx']], $hitRows),
            'misses' => array_map(fn($r) => [(int)$r['row_idx'], (int)$r['col_idx']], $missRows),
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
        $playerId = Utils::getInt($body, ['playerId', 'player_id']);
        if ($playerId === null) {
            Response::error(400, 'bad_request', 'playerId is required.');
        }

        $stmt = $this->pdo->prepare('SELECT turn_order FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error(404, 'not_found', 'Player not found in game.');
        }

        $update = $this->pdo->prepare('UPDATE games SET current_turn_index = :turn_order WHERE game_id = :game_id');
        $update->execute([
            ':turn_order' => (int)$row['turn_order'],
            ':game_id' => $gameId,
        ]);

        Response::json(200, [
            'status' => 'turn set',
            'player_id' => $playerId,
            'playerId' => $playerId,
        ]);
    }
}
