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
            foreach (getallheaders() as $name => $value) {
                $lower = strtolower($name);
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

    public static function requireTestPassword(): void
    {
        $headers = getallheaders();

        if (
            !isset($headers['X-Test-Password']) ||
            $headers['X-Test-Password'] !== 'clemson-test-2026'
        ) {
            Response::error(403, 'forbidden', 'Forbidden');
        }
    }
}
