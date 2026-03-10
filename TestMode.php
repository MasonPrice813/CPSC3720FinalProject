<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $expected = getenv('TEST_PASSWORD');
        $header = $_SERVER['HTTP_X_TEST_MODE'] ?? null;

        if (!$expected || $header !== $expected) {
            Response::error(403, 'Forbidden');
        }
    }
}
