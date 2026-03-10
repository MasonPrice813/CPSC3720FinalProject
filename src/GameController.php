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

        Response::json(200, [
            'status' => 'reset'
        ]);
    }

    public function createPlayer(): void
    {
        $body = Utils::getJsonBody();

        if (isset($body['player_id']) || isset($body['playerId'])) {
            Response::error(400, 'Client may not supply playerId.');
        }

        $displayName = trim(
            $body['username']
            ?? $body['playerName']
            ?? $body['display_name']
            ?? ''
        );

        if ($displayName === '') {
            Response::error(400, 'Username is required.');
        }

        if (mb_strlen($displayName) > 50) {
            Response::error(400, 'Username must be 50 characters or fewer.');
        }

        $dupStmt = $this->pdo->prepare("
            SELECT player_id
            FROM players
            WHERE LOWER(display_name) = LOWER(:display_name)
        ");
        $dupStmt->execute([':display_name' => $displayName]);
        $existing = $dupStmt->fetch();

        if ($existing) {
            Response::error(409, 'Display name already exists.');
        }

        $playerId = Utils::generateUuid();

        $stmt = $this->pdo->prepare("
            INSERT INTO players (
                player_id, display_name
            ) VALUES (
                :player_id, :display_name
            )
        ");

        $stmt->execute([
            ':player_id' => $playerId,
            ':display_name' => $displayName
        ]);

        Response::json(201, [
            'player_id' => $playerId,
            'playerId' => $playerId,
            'username' => $displayName,
            'display_name' => $displayName,
            'wins' => 0,
            'losses' => 0,
            'games_played' => 0,
            'total_moves' => 0,
            'total_shots' => 0,
            'total_hits' => 0
        ]);
    }

    public function getPlayerStats(string $playerId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT
                player_id,
                display_name,
                total_games,
                total_wins,
                total_losses,
                total_moves,
                total_shots,
                total_hits
            FROM players
            WHERE player_id = :player_id
        ");
        $stmt->execute([':player_id' => $playerId]);
        $player = $stmt->fetch();

        if (!$player) {
            Response::error(404, 'Player not found.');
        }

        $shots = (int)$player['total_shots'];
        $hits = (int)$player['total_hits'];
        $accuracy = $shots > 0 ? $hits / $shots : 0.0;

        Response::json(200, [
            'player_id' => $player['player_id'],
            'display_name' => $player['display_name'],
            'games_played' => (int)$player['total_games'],
            'wins' => (int)$player['total_wins'],
            'losses' => (int)$player['total_losses'],
            'total_moves' => (int)$player['total_moves'],
            'total_shots' => $shots,
            'total_hits' => $hits,
            'accuracy' => $accuracy
        ]);
    }

    public function createGame(): void
    {
        $body = Utils::getJsonBody();

        $gridSize = $body['grid_size'] ?? $body['gridSize'] ?? null;
        $maxPlayers = $body['max_players'] ?? $body['maxPlayers'] ?? null;
        $creatorId = $body['creator_id'] ?? $body['creatorId'] ?? null;

        if (!is_int($gridSize) || !is_int($maxPlayers)) {
            Response::error(400, 'grid_size and max_players must be integers.');
        }

        if ($gridSize < 5 || $gridSize > 15) {
            Response::error(400, 'grid_size must be between 5 and 15.');
        }

        if ($maxPlayers < 1) {
            Response::error(400, 'max_players must be at least 1.');
        }

        $gameId = Utils::generateUuid();

        $stmt = $this->pdo->prepare("
            INSERT INTO games (
                game_id, status, grid_size, max_players, current_turn_index
            ) VALUES (
                :game_id, 'waiting', :grid_size, :max_players, 0
            )
        ");
        $stmt->execute([
            ':game_id' => $gameId,
            ':grid_size' => $gridSize,
            ':max_players' => $maxPlayers
        ]);

        if ($creatorId !== null) {
            $player = $this->findPlayerById($creatorId);
            if (!$player) {
                Response::error(404, 'Creator not found.');
            }

            $joinStmt = $this->pdo->prepare("
                INSERT INTO game_players (game_id, player_id, turn_order)
                VALUES (:game_id, :player_id, 0)
            ");
            $joinStmt->execute([
                ':game_id' => $gameId,
                ':player_id' => $creatorId
            ]);

            $updateTurnStmt = $this->pdo->prepare("
                UPDATE games
                SET current_turn_player_id = :player_id
                WHERE game_id = :game_id
            ");
            $updateTurnStmt->execute([
                ':player_id' => $creatorId,
                ':game_id' => $gameId
            ]);
        }

        Response::json(201, [
            'game_id' => $gameId,
            'gameId' => $gameId,
            'status' => 'waiting',
            'grid_size' => $gridSize,
            'gridSize' => $gridSize,
            'max_players' => $maxPlayers,
            'maxPlayers' => $maxPlayers
        ]);
    }

    public function joinGame(string $gameId): void
    {
        $body = Utils::getJsonBody();

        $game = $this->findGameById($gameId);
        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        if ($game['status'] !== 'waiting') {
            Response::error(403, 'Game is not accepting players.');
        }

        $playerId = $body['player_id'] ?? $body['playerId'] ?? null;
        $displayName = trim(
            $body['name']
            ?? $body['username']
            ?? $body['playerName']
            ?? ''
        );

        if ($playerId !== null && ($displayName !== '')) {
            Response::error(400, 'Provide player_id or name, not both.');
        }

        if ($playerId !== null) {
            $player = $this->findPlayerById($playerId);
            if (!$player) {
                Response::error(403, 'Invalid player_id.');
            }
        } else {
            if ($displayName === '') {
                Response::error(400, 'Player name is required.');
            }

            $player = $this->findPlayerByDisplayName($displayName);

            if (!$player) {
                $newPlayerId = Utils::generateUuid();
                $insertPlayerStmt = $this->pdo->prepare("
                    INSERT INTO players (player_id, display_name)
                    VALUES (:player_id, :display_name)
                ");
                $insertPlayerStmt->execute([
                    ':player_id' => $newPlayerId,
                    ':display_name' => $displayName
                ]);

                $player = [
                    'player_id' => $newPlayerId,
                    'display_name' => $displayName
                ];
            }

            $playerId = $player['player_id'];
        }

        $dupStmt = $this->pdo->prepare("
            SELECT player_id
            FROM game_players
            WHERE game_id = :game_id AND player_id = :player_id
        ");
        $dupStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId
        ]);
        if ($dupStmt->fetch()) {
            Response::error(400, 'Player already joined this game.');
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM game_players
            WHERE game_id = :game_id
        ");
        $countStmt->execute([':game_id' => $gameId]);
        $joinedCount = (int)$countStmt->fetch()['cnt'];

        if ($joinedCount >= (int)$game['max_players']) {
            Response::error(409, 'Game is already full.');
        }

        $turnOrder = $joinedCount;

        $insertJoinStmt = $this->pdo->prepare("
            INSERT INTO game_players (game_id, player_id, turn_order)
            VALUES (:game_id, :player_id, :turn_order)
        ");
        $insertJoinStmt->execute([
            ':game_id' => $gameId,
            ':player_id' => $playerId,
            ':turn_order' => $turnOrder
        ]);

        if ($joinedCount === 0) {
            $updateTurnStmt = $this->pdo->prepare("
                UPDATE games
                SET current_turn_player_id = :player_id, current_turn_index = 0
                WHERE game_id = :game_id
            ");
            $updateTurnStmt->execute([
                ':player_id' => $playerId,
                ':game_id' => $gameId
            ]);
        }

        Response::json(201, [
            'player_id' => $playerId,
            'playerId' => $playerId,
            'game_id' => $gameId,
            'gameId' => $gameId,
            'status' => 'joined'
        ]);
    }

    public function getGame(string $gameId): void
    {
        $game = $this->findGameById($gameId);
        if (!$game) {
            Response::error(404, 'Game not found.');
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM game_players
            WHERE game_id = :game_id
        ");
        $countStmt->execute([':game_id' => $gameId]);
        $activePlayers = (int)$countStmt->fetch()['cnt'];

        Response::json(200, [
            'game_id' => $game['game_id'],
            'gameId' => $game['game_id'],
            'grid_size' => (int)$game['grid_size'],
            'gridSize' => (int)$game['grid_size'],
            'status' => $game['status'],
            'current_turn_index' => (int)$game['current_turn_index'],
            'active_players' => $activePlayers,
            'max_players' => (int)$game['max_players'],
            'maxPlayers' => (int)$game['max_players']
        ]);
    }

    private function findPlayerById(string $playerId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM players
            WHERE player_id = :player_id
        ");
        $stmt->execute([':player_id' => $playerId]);
        return $stmt->fetch();
    }

    private function findPlayerByDisplayName(string $displayName): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM players
            WHERE LOWER(display_name) = LOWER(:display_name)
        ");
        $stmt->execute([':display_name' => $displayName]);
        return $stmt->fetch();
    }

    private function findGameById(string $gameId): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM games
            WHERE game_id = :game_id
        ");
        $stmt->execute([':game_id' => $gameId]);
        return $stmt->fetch();
    }
}
