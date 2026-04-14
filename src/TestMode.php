<?php

class TestMode
{
    public static function getTestPassword(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_TEST_PASSWORD'] ?? null,
            $_SERVER['REDIRECT_HTTP_X_TEST_PASSWORD'] ?? null,
            $_SERVER['HTTP_X_TEST_MODE'] ?? null,
            $_SERVER['REDIRECT_HTTP_X_TEST_MODE'] ?? null,
        ];

        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                $lower = strtolower((string)$name);
                if ($lower === 'x-test-password' || $lower === 'x-test-mode') {
                    $candidates[] = $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string)$candidate;
            }
        }

        return null;
    }

    public static function requireTestMode(): void
    {
        $provided = self::getTestPassword();
        if ($provided === null || !hash_equals('clemson-test-2026', $provided)) {
            Response::error(403, 'forbidden', 'Forbidden');
        }
    }
}
