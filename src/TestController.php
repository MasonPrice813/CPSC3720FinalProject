<?php

require_once __DIR__ . '/TestMode.php';
require_once __DIR__ . '/Response.php';

class TestController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function restartGame($gameId) {
        // 🔐 Enforce test mode auth
        if (!TestMode::isAuthorized()) {
            return Response::json(["error" => "forbidden"], 403);
        }

        if (!is_numeric($gameId) || $gameId <= 0) {
            return Response::json(["error" => "Game not found"], 404);
        }

        $pdo = $this->db->getConnection();

        // Check if game exists
        $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            return Response::json(["error" => "Game not found"], 404);
        }

        try {
            $pdo->beginTransaction();

            // Preserve players with stats > 0
            $players = $pdo->query("
                SELECT * FROM players
                WHERE games_played > 0
                   OR total_shots > 0
                   OR total_hits > 0
                   OR wins > 0
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Clear ALL game-related tables
            $pdo->exec("TRUNCATE moves, ships, game_players, games RESTART IDENTITY CASCADE");

            // Reinsert preserved players (reset sequence after)
            if (!empty($players)) {
                $insert = $pdo->prepare("
                    INSERT INTO players (player_id, username, games_played, wins, total_shots, total_hits)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($players as $p) {
                    $insert->execute([
                        $p['player_id'],
                        $p['username'],
                        $p['games_played'],
                        $p['wins'],
                        $p['total_shots'],
                        $p['total_hits']
                    ]);
                }

                // Reset sequence correctly
                $pdo->exec("
                    SELECT setval(
                        pg_get_serial_sequence('players', 'player_id'),
                        (SELECT COALESCE(MAX(player_id), 1) FROM players)
                    )
                ");
            }

            // Recreate the game with SAME ID
            $insertGame = $pdo->prepare("
                INSERT INTO games (game_id, grid_size, max_players, status, total_moves, current_turn_player_id)
                VALUES (?, ?, ?, 'waiting_setup', 0, NULL)
            ");

            $insertGame->execute([
                $gameId,
                $game['grid_size'],
                $game['max_players']
            ]);

            // Reset game_id sequence
            $pdo->exec("
                SELECT setval(
                    pg_get_serial_sequence('games', 'game_id'),
                    (SELECT COALESCE(MAX(game_id), 1) FROM games)
                )
            ");

            $pdo->commit();

            return Response::json([
                "status" => "restarted",
                "game_id" => (int)$gameId
            ], 200);

        } catch (Exception $e) {
            $pdo->rollBack();
            return Response::json([
                "error" => "restart failed",
                "details" => $e->getMessage()
            ], 500);
        }
    }
}