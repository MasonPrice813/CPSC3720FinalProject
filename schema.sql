CREATE TABLE IF NOT EXISTS games (
   id VARCHAR(36) PRIMARY KEY,
   status VARCHAR(20) NOT NULL DEFAULT 'waiting',
   grid_size INT NOT NULL,
   max_players INT NOT NULL,
   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS players (
   id VARCHAR(36) PRIMARY KEY,
   game_id VARCHAR(36) NOT NULL,
   name VARCHAR(50) NOT NULL,
   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   CONSTRAINT fk_players_game
       FOREIGN KEY (game_id) REFERENCES games(id)
       ON DELETE CASCADE,
   CONSTRAINT uq_game_player_name UNIQUE (game_id, name)
);
