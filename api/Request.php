<?php

declare(strict_types=1);

class Request
{
    private array $query;
    private array $body;
    private array $headers;

    public function __construct()
    {
        $this->query   = $_GET;
        $this->headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);

        $raw = (string) file_get_contents('php://input');
        $contentType = $this->headers['content-type'] ?? '';

        if ($raw !== '' && strpos($contentType, 'application/json') !== false) {
            $this->body = (array) (json_decode($raw, true) ?? []);
        } else {
            $this->body = $_POST;
        }
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return trim((string) $this->input($key, $default));
    }

    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['authorization'] ?? '';
        if (strpos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Strip the base directory so routes are relative to the api folder
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        return '/' . ltrim($path, '/');
    }
}
