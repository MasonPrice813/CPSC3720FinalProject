# Battleship API – CPSC 3750 Final Project (Phase 1)

## Project Overview
This project implements a RESTful API for a multiplayer Battleship game using PHP and PostgreSQL. The system allows players to create accounts, create and join games, place ships, and take turns firing at other players. It also tracks player statistics such as wins, losses, shots, and accuracy across multiple games.

The API is designed to support automated testing by including deterministic test endpoints. These allow graders to place ships at exact coordinates, inspect board state, and reset games without affecting player statistics. The application is deployed on Render and connects to a PostgreSQL database using PDO.

The goal of this project is to demonstrate a reliable backend system with proper validation, game logic, and persistent data handling.

## Architecture Summary
The backend is written in PHP and follows a controller-based structure that separates routing, game logic, and database interactions. The entry point is handled through `public/index.php`, which routes requests to the appropriate controllers.

Game data and player information are stored in a PostgreSQL database. The application connects using a `DATABASE_URL` environment variable provided by Render. The system includes controllers for players, games, and test functionality, along with helper utilities for handling requests and formatting JSON responses.

The project is deployed using Docker on Render to ensure a consistent runtime environment.

## API Description

### Player Endpoints
- **POST /api/players**  
  Create a new player and return a unique `player_id`

- **GET /api/players/{id}/stats**  
  Retrieve player statistics

### Game Endpoints
- **POST /api/games**  
  Create a new game

- **POST /api/games/{gameId}/join**  
  Join an existing game

- **GET /api/games/{gameId}**  
  Get the current game state

### Gameplay Endpoints
- **POST /api/games/{gameId}/place**  
  Place ships on the board

- **POST /api/games/{gameId}/fire**  
  Fire at a coordinate

- **GET /api/games/{gameId}/moves**  
  Retrieve move history

### Test Mode Endpoints
These endpoints are used for automated grading and deterministic testing.

- **POST /api/test/games/{gameId}/ships**  
  Place ships deterministically

- **GET /api/test/games/{gameId}/board/{playerId}**  
  Reveal board state

- **POST /api/test/games/{gameId}/restart**  
  Reset game state (does not reset player stats)

All test endpoints require the header:
X-Test-Password: clemson-test-2026

## Team Members
- Shihab Abdelrahim  
- Mason Price  

## AI Tools Used
ChatGPT (OpenAI) was used throughout the project as a development assistant. It helped with debugging API logic, improving SQL queries, and identifying edge cases during testing.

## Roles of Human Developers and AI
The human developers were responsible for designing the system, implementing the API, building the database schema, deploying the project, and testing all endpoints.

- Shihab focused more on system logic and overall planning  
- Mason focused more on implementation and coding  

AI was used as a support tool to help troubleshoot bugs, explain concepts, and suggest possible improvements. All final decisions and implementations were made by the developers.
