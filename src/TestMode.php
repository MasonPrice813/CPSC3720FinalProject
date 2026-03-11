<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $provided =
            $_SERVER['HTTP_X_TEST_MODE'] ??
            $_SERVER['HTTP_X_TEST_PASSWORD'] ??
            null;

        if ($provided === null) {
            Response::error(403, 'Forbidden');
        }

        $allowedPasswords = [
            'clemson-test-2026',
            'battleship_test_mode'
        ];

        $envPassword = getenv('TEST_PASSWORD');
        if ($envPassword !== false && $envPassword !== '') {
            $allowedPasswords[] = $envPassword;
        }

        $isValid = false;
        foreach ($allowedPasswords as $password) {
            if (hash_equals($password, $provided)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            Response::error(403, 'Forbidden');
        }
    }
}