<?php

class TestMode
{
    public static function getTestPassword(): ?string
    {
        $serverKeys = [
            'HTTP_X_TEST_PASSWORD',
            'REDIRECT_HTTP_X_TEST_PASSWORD',
            'HTTP_X_TEST_MODE',
            'REDIRECT_HTTP_X_TEST_MODE',
        ];

        foreach ($serverKeys as $key) {
            if (!empty($_SERVER[$key])) {
                return (string)$_SERVER[$key];
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                $normalized = strtolower((string)$name);
                if ($normalized === 'x-test-password' || $normalized === 'x-test-mode') {
                    return (string)$value;
                }
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
