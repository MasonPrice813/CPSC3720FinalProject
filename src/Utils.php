<?php

class Utils
{
    public static function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        if ($raw !== '' && $decoded === null) {
            Response::error(400, 'Invalid JSON body.');
        }

        return $decoded ?? [];
    }

    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function normalizeName(string $name): string
    {
        return trim($name);
    }
}