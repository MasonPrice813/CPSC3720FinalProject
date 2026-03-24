<?php

class TestMode
{
    public static function requireTestMode(): void
    {
        $testMode = getenv('TEST_MODE');

        if ($testMode !== false) {
            $normalized = strtolower(trim($testMode));
            $enabled = in_array($normalized, ['1', 'true', 'yes', 'on'], true);

            if (!$enabled) {
                Response::error(403, 'Forbidden');
            }
        }

        $provided = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? null;

        if ($provided === null || !hash_equals('clemson-test-2026', $provided)) {
            Response::error(403, 'Forbidden');
        }
    }
}
