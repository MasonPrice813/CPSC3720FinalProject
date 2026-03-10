<?php

class Response
{
    public static function json(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    public static function error(int $statusCode, string $message): void
    {
        self::json($statusCode, ['error' => $message]);
    }
}