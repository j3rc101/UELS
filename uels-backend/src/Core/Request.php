<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    public string $method;
    public string $path;
    public array $query;
    private ?array $body = null;

    public static function fromGlobals(): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $r->query = $_GET;

        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        // Works for both /uels-backend/public/api/... and /uels-backend/api/...
        foreach ([$base, rtrim(dirname($base), '/')] as $candidate) {
            if ($candidate !== '' && $candidate !== '.' && strpos($uri, $candidate) === 0) {
                $uri = substr($uri, strlen($candidate));
                break;
            }
        }
        $r->path = '/' . trim($uri, '/');
        return $r;
    }

    public function get(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /** Parsed JSON body (or form fields). */
    public function input(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException('Request body must be valid JSON', 400);
            }
            return $this->body = $decoded;
        }
        return $this->body = $_POST;
    }
}
