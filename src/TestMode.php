<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $testMode = getenv('TEST_MODE');
        if ($testMode !== false && strtolower($testMode) !== 'true') {
            Response::error(403, 'Forbidden');
        }

        $provided =
            $_SERVER['HTTP_X_TEST_MODE'] ??
            $_SERVER['HTTP_X_TEST_PASSWORD'] ??
            null;

        if ($provided === null) {
            Response::error(403, 'Forbidden');
        }

        $envPassword = getenv('TEST_PASSWORD');

        /*
        If TEST_PASSWORD is configured, require an exact match to it.
        Otherwise allow local/dev fallback passwords.
        */
        if ($envPassword !== false && $envPassword !== '') {
            if (!hash_equals($envPassword, $provided)) {
                Response::error(403, 'Forbidden');
            }
            return;
        }

        $fallbackPasswords = [
            'clemson-test-2026',
            'battleship_test_mode'
        ];

        if (!in_array($provided, $fallbackPasswords, true)) {
            Response::error(403, 'Forbidden');
        }
    }
}