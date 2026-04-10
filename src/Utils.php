<?php

class Utils
{
    public static function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Response::error(400, 'bad_request', 'Invalid JSON body.');
        }

        return $decoded;
    }

    public static function normalizeName(string $name): string
    {
        return trim($name);
    }

    public static function getInt(array $body, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $body) && is_int($body[$key])) {
                return $body[$key];
            }
        }

        return null;
    }

    public static function getString(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $body) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return null;
    }
}
