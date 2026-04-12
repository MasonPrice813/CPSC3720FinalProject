<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $provided = $_SERVER['HTTP_X_TEST_PASSWORD']
            ?? $_SERVER['REDIRECT_HTTP_X_TEST_PASSWORD']
            ?? null;

        if ($provided === null || !hash_equals('clemson-test-2026', (string)$provided)) {
            Response::error(403, 'forbidden', 'Forbidden');
        }
    }
}
