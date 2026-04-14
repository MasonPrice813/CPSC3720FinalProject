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
        $this->pdo->exec('TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE');
        Response::json(200, ['status' => 'reset']);
    }

    public function createPlayer(): void
    {
        $body = Utils::getJsonBody();

        if (array_key_exists('player_id', $body) || array_key_exists('playerId', $body)) {
            Response::error(400, 'bad_request', 'Client may not supply player_id.');
        }

        $rawUsername = Utils::getString($body, ['username', 'playerName']);
        if ($rawUsername === null) {
            Response::error(400, 'bad_request', 'Username is required.');
        }

        $username = Utils::normalizeName($rawUsername);
        if ($username === '') {
            Response::error(400, 'bad_request', 'Username is required.');
        }

        if (strlen($username) > 30) {
            Response::error(400, 'bad_request', 'Username may not exceed 30 characters.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            Response::error(400, 'bad_request', 'Username may only contain letters, numbers, and underscores.');
        }

        $existing = $this->pdo->prepare('SELECT player_id, total_games, total_wins, total_losses, total_shots, total_hits FROM players WHERE LOWER(display_name) = LOWER(:u) LIMIT 1');
        $existing->execute([':u' => $username]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Preserve the original assignment behavior: duplicates are conflicts.
            Response::error(409, 'conflict', 'Username already exists.', [
                'player_id' => (int)$row['player_id'],
                'username' => $username,
            ]);
        }

        $stmt = $this->pdo->prepare('INSERT INTO players (display_name) VALUES (:display_name) RETURNING player_id');
        $stmt->execute([':display_name' => $username]);
        $playerId = (int)$stmt->fetchColumn();

        Response::json(201, [
            'player_id' => $playerId,
            'username' => $username,
        ]);
    }

    public function listPlayers(): void
    {
        $stmt = $this->pdo->query('SELECT player_id, display_name FROM players ORDER BY player_id ASC');
        $players = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $players[] = [
                'player_id' => (int)$row['player_id'],
                'username' => $row['display_name'],
            ];
        }
        Response::json(200, ['players' => $players]);
    }

    public function getPlayerStats(int $playerId): void
    {
        if ($playerId <= 0) {
            Response::error(404, 'not_found', 'Player not found.');
        }

        $player = $this->getPlayerRow($playerId);
        if (!$player) {
            Response::error(404, 'not_found', 'Player not found.');
        }

        $shots = (int)$player['total_shots'];
        $hits = (int)$player['total_hits'];
        $gamesPlayed = (int)$player['total_games'];
        $wins = (int)$player['total_wins'];
        $losses = (int)$player['total_losses'];
        $accuracy = $shots > 0 ? $hits / $shots : 0.0;

        Response::json(200, [
            'player_id' => (int)$player['player_id'],
            'games_played' => $gamesPlayed,
            'games' => $gamesPlayed,
            'wins' => $wins,
            'losses' => $losses,
            'total_shots' => $shots,
            'shots' => $shots,
            'total_hits' => $hits,
            'hits' => $hits,
            'accuracy' => $accuracy,
        ]);
    }

    public function createGame(): void
    {
        $body = Utils::getJsonBody();

        $creatorId = Utils::getInt($body, ['creator_id']);
        $gridSize = Utils::getInt($body, ['grid_size', 'gridSize']);
        $maxPlayers = Utils::getInt($body, ['max_players', 'maxPlayers']);

        if ($creatorId === null || $gridSize === null || $maxPlayers === null) {
            Response::error(400, 'bad_request', 'creator_id, grid_size, and max_players are required integers.');
        }
        if ($gridSize < 5 || $gridSize > 15) {
            Response::error(400, 'bad_request', 'grid_size must be between 5 and 15.');
        }
        if ($maxPlayers < 2) {
            Response::error(400, 'bad_request', 'max_players must be at least 2.');
        }

        $creator = $this->getPlayerRow($creatorId);
        if (!$creator) {
            Response::error(400, 'bad_request', 'creator_id does not refer to an existing player.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO games (grid_size, max_players, status, current_turn_index) VALUES (:grid_size, :max_players, 'waiting_setup', 0) RETURNING game_id");
            $stmt->execute([
                ':grid_size' => $gridSize,
                ':max_players' => $maxPlayers,
            ]);
            $gameId = (int)$stmt->fetchColumn();

            // Keep the creator auto-join behavior because the current passing setup assumes it.
            $joinStmt = $this->pdo->prepare('INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, 0)');
            $joinStmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $creatorId,
            ]);

            $this->pdo->commit();

            Response::json(201, [
                'game_id' => $gameId,
                'grid_size' => $gridSize,
                'max_players' => $maxPlayers,
                'status' => 'waiting_setup',
                'active_players' => 1,
                'creator_id' => $creatorId,
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listGames(): void
    {
        $stmt = $this->pdo->query('SELECT game_id, grid_size, max_players, status FROM games ORDER BY game_id ASC');
        $games = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $games[] = [
                'game_id' => (int)$row['game_id'],
                'grid_size' => (int)$row['grid_size'],
                'max_players' => (int)$row['max_players'],
                'status' => $row['status'],
            ];
        }
        Response::json(200, ['games' => $games]);
    }

    public function joinGame(int $gameId): void
    {
        $body = Utils::getJsonBody();
        $playerId = Utils::getInt($body, ['player_id', 'playerId']);
        if ($playerId === null) {
            Response::error(400, 'bad_request', 'player_id required.');
        }

        if ($gameId <= 0) {
            Response::error(404, 'not_found', 'Game not found.');
        }

        if (!$this->getPlayerRow($playerId)) {
            Response::error(404, 'not_found', 'Player not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $gameStmt = $this->pdo->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
            $gameStmt->execute([':game_id' => $gameId]);
            $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Game not found.');
            }

            if ($game['status'] !== 'waiting_setup') {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Game already started.');
            }

            $dupStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
            $dupStmt->execute([':game_id' => $gameId, ':player_id' => $playerId]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Player already joined this game.');
            }

            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id');
            $countStmt->execute([':game_id' => $gameId]);
            $currentPlayers = (int)$countStmt->fetchColumn();
            if ($currentPlayers >= (int)$game['max_players']) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Game is full.');
            }

            $insert = $this->pdo->prepare('INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, :turn_order)');
            $insert->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':turn_order' => $currentPlayers,
            ]);

            $this->pdo->commit();
            Response::json(200, [
                'status' => 'joined',
                'game_id' => $gameId,
                'player_id' => $playerId,
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getGame(int $gameId): void
    {
        if ($gameId <= 0) {
            Response::error(404, 'not_found', 'Game not found.');
        }
        $game = $this->getGameRow($gameId);
        if (!$game) {
            Response::error(404, 'not_found', 'Game not found.');
        }

        $playersStmt = $this->pdo->prepare('SELECT gp.player_id, gp.turn_order, p.display_name FROM game_players gp JOIN players p ON p.player_id = gp.player_id WHERE gp.game_id = :game_id ORDER BY gp.turn_order ASC');
        $playersStmt->execute([':game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

        $players = [];
        foreach ($playerRows as $row) {
            $remainingStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ships s WHERE s.game_id = :game_id AND s.player_id = :player_id AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row_idx = s.row_idx AND m.col_idx = s.col_idx AND m.result = 'hit')");
            $remainingStmt->execute([
                ':game_id' => $gameId,
                ':player_id' => (int)$row['player_id'],
            ]);

            $players[] = [
                'player_id' => (int)$row['player_id'],
                'username' => $row['display_name'],
                'turn_order' => (int)$row['turn_order'],
                'ships_remaining' => (int)$remainingStmt->fetchColumn(),
            ];
        }

        $movesCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM moves WHERE game_id = :game_id');
        $movesCountStmt->execute([':game_id' => $gameId]);
        $totalMoves = (int)$movesCountStmt->fetchColumn();

        $currentTurnPlayerId = null;
        if ($game['status'] === 'playing') {
            $currentTurnPlayerId = $this->getCurrentTurnPlayerId($gameId);
        }

        $response = [
            'game_id' => (int)$game['game_id'],
            'grid_size' => (int)$game['grid_size'],
            'max_players' => (int)$game['max_players'],
            'status' => $game['status'],
            'current_turn_index' => (int)$game['current_turn_index'],
            'current_turn_player_id' => $currentTurnPlayerId,
            'active_players' => count($players),
            'players' => $players,
            'total_moves' => $totalMoves,
        ];
        if ($game['winner_id'] !== null) {
            $response['winner_id'] = (int)$game['winner_id'];
        }

        Response::json(200, $response);
    }

    public function placeShips(int $gameId): void
    {
        $body = Utils::getJsonBody();
        $playerId = Utils::getInt($body, ['player_id', 'playerId']);
        $ships = $body['ships'] ?? null;

        if ($playerId === null) {
            Response::error(400, 'bad_request', 'player_id required.');
        }
        if (!is_array($ships)) {
            Response::error(400, 'bad_request', 'ships must be an array.');
        }
        $this->requireExistingPlayer($playerId);

        $this->pdo->beginTransaction();
        try {
            $gameStmt = $this->pdo->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
            $gameStmt->execute([':game_id' => $gameId]);
            $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Game not found.');
            }
            if ($game['status'] !== 'waiting_setup') {
                $this->pdo->rollBack();
                Response::error(409, 'conflict', 'Ships can only be placed while the game is in setup.');
            }
            if (!$this->playerInGame($gameId, $playerId)) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Player is not in this game.');
            }
            if ($this->playerAlreadyPlacedShips($gameId, $playerId)) {
                $this->pdo->rollBack();
                Response::error(409, 'conflict', 'Ships already placed for this player.');
            }

            $coordinates = [];
            foreach ($ships as $ship) {
                if (is_array($ship) && array_key_exists('row', $ship) && array_key_exists('col', $ship)) {
                    $coordinates[] = ['row' => (int)$ship['row'], 'col' => (int)$ship['col']];
                    continue;
                }
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Ships must be objects with row and col fields.');
            }

            if (count($coordinates) !== 3) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Exactly 3 ship coordinates are required.');
            }

            $gridSize = (int)$game['grid_size'];
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

            if ($this->allPlayersPlacedShips($gameId)) {
                $this->pdo->prepare("UPDATE games SET status = 'playing', current_turn_index = 0 WHERE game_id = :game_id")
                    ->execute([':game_id' => $gameId]);
            }

            $this->pdo->commit();
            Response::json(200, [
                'status' => 'placed',
                'game_id' => $gameId,
                'player_id' => $playerId,
            ]);
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
        $playerId = Utils::getInt($body, ['player_id', 'playerId']);
        $row = Utils::getInt($body, ['row']);
        $col = Utils::getInt($body, ['col']);

        if ($playerId === null || $row === null || $col === null) {
            Response::error(400, 'bad_request', 'player_id, row, and col are required integers.');
        }
        $this->requireExistingPlayer($playerId);

        $this->pdo->beginTransaction();
        try {
            $gameStmt = $this->pdo->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
            $gameStmt->execute([':game_id' => $gameId]);
            $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
            if (!$game) {
                $this->pdo->rollBack();
                Response::error(404, 'not_found', 'Game not found.');
            }
            if (!$this->playerInGame($gameId, $playerId)) {
                $this->pdo->rollBack();
                Response::error(403, 'forbidden', 'Player is not in this game.');
            }
            if ($game['status'] === 'finished') {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Game already finished.');
            }
            if ($game['status'] !== 'playing') {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Game is not in playing state.');
            }

            $gridSize = (int)$game['grid_size'];
            if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
                $this->pdo->rollBack();
                Response::error(400, 'bad_request', 'Shot out of bounds.');
            }

            // Duplicate check before turn check so duplicates consistently return 409.
            $dupStmt = $this->pdo->prepare('SELECT COUNT(*) FROM moves WHERE game_id = :game_id AND row_idx = :row AND col_idx = :col');
            $dupStmt->execute([
                ':game_id' => $gameId,
                ':row' => $row,
                ':col' => $col,
            ]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $this->pdo->rollBack();
                Response::error(409, 'conflict', 'Cell already targeted.');
            }

            $currentPlayerId = $this->getCurrentTurnPlayerId($gameId);
            if ($currentPlayerId === null || $currentPlayerId !== $playerId) {
                $this->pdo->rollBack();
                Response::error(403, 'forbidden', 'not your turn');
            }

            $hitStmt = $this->pdo->prepare('SELECT player_id FROM ships WHERE game_id = :game_id AND row_idx = :row AND col_idx = :col AND player_id <> :player_id LIMIT 1');
            $hitStmt->execute([
                ':game_id' => $gameId,
                ':row' => $row,
                ':col' => $col,
                ':player_id' => $playerId,
            ]);
            $hitShipOwner = $hitStmt->fetchColumn();
            $result = $hitShipOwner !== false ? 'hit' : 'miss';

            $insertMove = $this->pdo->prepare('INSERT INTO moves (game_id, player_id, row_idx, col_idx, result) VALUES (:game_id, :player_id, :row, :col, :result)');
            $insertMove->execute([
                ':game_id' => $gameId,
                ':player_id' => $playerId,
                ':row' => $row,
                ':col' => $col,
                ':result' => $result,
            ]);

            $this->pdo->prepare('UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + :hit_inc WHERE player_id = :player_id')
                ->execute([
                    ':hit_inc' => $result === 'hit' ? 1 : 0,
                    ':player_id' => $playerId,
                ]);

            $winnerId = $this->determineWinner($gameId);
            if ($winnerId !== null) {
                $this->pdo->prepare("UPDATE games SET status = 'finished', winner_id = :winner_id WHERE game_id = :game_id")
                    ->execute([
                        ':winner_id' => $winnerId,
                        ':game_id' => $gameId,
                    ]);
                $this->updateFinalPlayerStats($gameId, $winnerId);
                $this->pdo->commit();

                Response::json(200, [
                    'result' => $result,
                    'next_player_id' => null,
                    'game_status' => 'finished',
                    'status' => 'finished',
                    'winner_id' => $winnerId,
                ]);
            }

            $nextIndex = $this->getNextTurnIndex($gameId);
            $this->pdo->prepare('UPDATE games SET current_turn_index = :turn_index WHERE game_id = :game_id')
                ->execute([
                    ':turn_index' => $nextIndex,
                    ':game_id' => $gameId,
                ]);
            $nextPlayerId = $this->getPlayerIdByTurnOrder($gameId, $nextIndex);

            $this->pdo->commit();
            Response::json(200, [
                'result' => $result,
                'next_player_id' => $nextPlayerId,
                'game_status' => 'playing',
                'status' => 'playing',
                'state' => 'active',
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
        if ($gameId <= 0) {
            Response::error(404, 'not_found', 'Game not found.');
        }
        $game = $this->getGameRow($gameId);
        if (!$game) {
            Response::error(404, 'not_found', 'Game not found.');
        }

        $stmt = $this->pdo->prepare('SELECT move_id, player_id, row_idx, col_idx, result, created_at FROM moves WHERE game_id = :game_id ORDER BY created_at ASC, move_id ASC');
        $stmt->execute([':game_id' => $gameId]);

        $moves = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $moves[] = [
                'move_id' => (int)$row['move_id'],
                'player_id' => (int)$row['player_id'],
                'row' => (int)$row['row_idx'],
                'col' => (int)$row['col_idx'],
                'result' => $row['result'],
                'timestamp' => $row['created_at'],
                'created_at' => $row['created_at'],
            ];
        }

        Response::json(200, [
            'game_id' => $gameId,
            'moves' => $moves,
        ]);
    }

    private function requireExistingPlayer(int $playerId): void
    {
        if (!$this->getPlayerRow($playerId)) {
            Response::error(404, 'not_found', 'Player not found.');
        }
    }

    private function getPlayerRow(int $playerId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $playerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getGameRow(int $gameId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $gameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function playerInGame(int $gameId, int $playerId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function playerAlreadyPlacedShips(int $gameId, int $playerId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ships WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function allPlayersPlacedShips(int $gameId): bool
    {
        $playerCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id');
        $playerCountStmt->execute([':game_id' => $gameId]);
        $playerCount = (int)$playerCountStmt->fetchColumn();
        if ($playerCount < 2) {
            return false;
        }

        $placedCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM (SELECT gp.player_id FROM game_players gp LEFT JOIN ships s ON s.game_id = gp.game_id AND s.player_id = gp.player_id WHERE gp.game_id = :game_id GROUP BY gp.player_id HAVING COUNT(s.row_idx) = 3) placed_players');
        $placedCountStmt->execute([':game_id' => $gameId]);
        return (int)$placedCountStmt->fetchColumn() === $playerCount;
    }

    private function getCurrentTurnPlayerId(int $gameId): ?int
    {
        $gameStmt = $this->pdo->prepare('SELECT current_turn_index FROM games WHERE game_id = :game_id');
        $gameStmt->execute([':game_id' => $gameId]);
        $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$game) {
            return null;
        }
        return $this->getPlayerIdByTurnOrder($gameId, (int)$game['current_turn_index']);
    }

    private function getNextTurnIndex(int $gameId): int
    {
        $gameStmt = $this->pdo->prepare('SELECT current_turn_index FROM games WHERE game_id = :game_id');
        $gameStmt->execute([':game_id' => $gameId]);
        $game = $gameStmt->fetch(PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :game_id');
        $countStmt->execute([':game_id' => $gameId]);
        $playerCount = (int)$countStmt->fetchColumn();

        if (!$game || $playerCount <= 0) {
            return 0;
        }
        return (((int)$game['current_turn_index']) + 1) % $playerCount;
    }

    private function getPlayerIdByTurnOrder(int $gameId, int $turnOrder): ?int
    {
        $stmt = $this->pdo->prepare('SELECT player_id FROM game_players WHERE game_id = :game_id AND turn_order = :turn_order LIMIT 1');
        $stmt->execute([
            ':game_id' => $gameId,
            ':turn_order' => $turnOrder,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['player_id'] : null;
    }

    private function determineWinner(int $gameId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT s.player_id FROM ships s WHERE s.game_id = :game_id AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row_idx = s.row_idx AND m.col_idx = s.col_idx AND m.result = 'hit') GROUP BY s.player_id");
        $stmt->execute([':game_id' => $gameId]);
        $alive = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($alive) === 1) {
            return (int)$alive[0]['player_id'];
        }
        return null;
    }

    private function updateFinalPlayerStats(int $gameId, int $winnerId): void
    {
        $players = $this->pdo->prepare('SELECT player_id FROM game_players WHERE game_id = :game_id');
        $players->execute([':game_id' => $gameId]);

        foreach ($players->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $playerId = (int)$row['player_id'];
            $this->pdo->prepare('UPDATE players SET total_games = total_games + 1 WHERE player_id = :player_id')
                ->execute([':player_id' => $playerId]);

            if ($playerId === $winnerId) {
                $this->pdo->prepare('UPDATE players SET total_wins = total_wins + 1 WHERE player_id = :player_id')
                    ->execute([':player_id' => $playerId]);
            } else {
                $this->pdo->prepare('UPDATE players SET total_losses = total_losses + 1 WHERE player_id = :player_id')
                    ->execute([':player_id' => $playerId]);
            }
        }
    }
}
