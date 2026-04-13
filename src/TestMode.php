<?php

class TestMode
{
    public static function getTestPassword(): ?string
    {
        // Try standard CGI/FastCGI header first
        if (!empty($_SERVER['HTTP_X_TEST_PASSWORD'])) {
            return $_SERVER['HTTP_X_TEST_PASSWORD'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_X_TEST_PASSWORD'])) {
            return $_SERVER['REDIRECT_HTTP_X_TEST_PASSWORD'];
        }

        // Apache may strip custom headers; use getallheaders() as fallback
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // getallheaders() keys are title-cased
            if (!empty($headers['X-Test-Password'])) {
                return $headers['X-Test-Password'];
            }
            // Some environments lowercase all headers
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'x-test-password') {
                    return $value;
                }
            }
        }

        return null;
    }

    public static function requireTestMode(): void
    {
        $provided = self::getTestPassword();

        if ($provided === null || !hash_equals('clemson-test-2026', (string)$provided)) {
            Response::error(403, 'forbidden', 'Forbidden');
        }
    }
}
