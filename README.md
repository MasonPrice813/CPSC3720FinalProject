Battleship API – CPSC 3720 Final Project, Phase 1 Part A

Project Summary:
This project implements a RESTful API for a multiplayer Battleship game using PHP and PostgreSQL. The system allows players to create accounts, create games, join games, and place ships on a board. The API is designed to support automated testing by providing deterministic endpoints that allow graders to place ships at specific coordinates and inspect the board state. The application is deployed on Render and communicates with a PostgreSQL database using PDO. The goal of the project is to demonstrate the design of a reliable backend system with proper validation, game state management, and structured API endpoints.

Architecture Summary:
The backend of the project is written in PHP and organized using a controller-based structure that separates routing, game logic, and database access. The API entry point is handled by public and index php files, which routes requests to controllers responsible for managing players, games, and test endpoints. Game state and player information are stored in a PostgreSQL database, and the application connects to the database using a DATABASE_URL environment variable provided by Render. The system includes controllers for gameplay logic, deterministic testing endpoints, and utility helpers for request handling and response formatting. The project is deployed using Docker on Render to ensure consistent runtime behavior.

API Description:
The API exposes endpoints for managing players and games. Players can be created using the /api/players endpoint, which returns a unique player ID and initializes player statistics. Games can be created using /api/games, which accepts a grid size and maximum number of players and returns a unique game ID with an initial status of waiting. Players can join games using /api/games/{gameId}/join. To support automated grading, the API also includes deterministic test endpoints under /api/test. These endpoints allow graders to place ships using /api/test/games/{gameId}/ships, reveal the board state using /api/test/games/{gameId}/board, and reset game state when necessary. All test endpoints require authentication using a special X-Test-Mode header.

Team Members:
The project was developed by Shihab Abdelrahim and Mason Price.

AI Tools Used:
ChatGPT (OpenAI) was used as a development assistant during the project. It was used to help debug API endpoints, explain backend implementation details, assist with database interaction issues, and help identify formatting or validation problems related to the automated grading system.


Roles of Human Developers and AI:
The human developers, Shihab and Mason, were responsible for designing the system architecture, implementing the backend API, designing the database schema, integrating the API with the database, deploying the application to Render, and testing all endpoints. Shihab did more of the logic, and planning of the project while Mason worked more on the implementation and coding portion of the project. AI tools were used only as a support tool to help troubleshoot errors, explain concepts, and suggest potential fixes, while all final implementation decisions and integrations were performed by the developers.