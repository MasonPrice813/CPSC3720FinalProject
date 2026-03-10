<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $testMode = getenv('TEST_MODE');
        if ($testMode !== false && strtolower($testMode) !== 'true') {
            Response::error(403, 'Forbidden');
        }

        $passwordHeader = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? null;
        $modeHeader = $_SERVER['HTTP_X_TEST_MODE'] ?? null;

        $acceptedPasswords = [
            'clemson-test-2026'
        ];

        $envPassword = getenv('TEST_PASSWORD');
        if ($envPassword) {
            $acceptedPasswords[] = $envPassword;
        }

        $defaultLocalPassword = 'battleship_test_mode';
        $acceptedPasswords[] = $defaultLocalPassword;

        $provided = $passwordHeader ?? $modeHeader;

        if ($provided === null || !in_array($provided, $acceptedPasswords, true)) {
            Response::error(403, 'Forbidden');
        }
    }
}
