<?php

class Response
{
    public static function json(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(int $statusCode, string $errorCode, ?string $message = null, array $extra = []): void
    {
        $payload = array_merge([
            'error' => true,
            'code' => $errorCode,
        ], $extra);

        if ($message !== null) {
            $payload['message'] = $message;
        }

        self::json($statusCode, $payload);
    }
}