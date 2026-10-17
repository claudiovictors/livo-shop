<?php

declare(strict_types=1);

namespace Tigger\Http;

use JsonException;
use Tigger\Exceptions\HttpException;
use Tigger\Routing\Path;

/**
 * An incoming HTTP request (the `$req` of your handlers).
 *
 * Build it from PHP globals with {@see Request::fromGlobals()}, or
 * construct it manually (handy for tests).
 */
final class Request
{
    /** @var array<string,string> */
    private array $params = [];

    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param string               $method   HTTP method (any case).
     * @param string               $path     Decoded request path, relative to the app base path.
     * @param array<string,mixed>  $query    Query-string parameters.
     * @param array<string,mixed>  $body     Parsed request body.
     * @param array<string,string> $headers  Request headers (names are lower-cased).
     * @param array<string,string> $cookies  Request cookies.
     * @param array<string,mixed>  $files    Uploaded files (`$_FILES` shape).
     * @param string               $ip       Client IP (REMOTE_ADDR; proxy headers are never trusted).
     * @param bool                 $secure   Whether the request came over HTTPS.
     * @param string               $basePath Sub-directory the app lives in ("" when at the web root).
     * @param string               $rawBody  Raw request body, exactly as received (needed to verify webhook signatures).
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly string $ip = '',
        private readonly bool $secure = false,
        private readonly string $basePath = '',
        private readonly string $rawBody = '',
    ) {
        $this->headers = array_change_key_case($this->headers, CASE_LOWER);
    }

    /**
     * Builds a request from PHP's superglobals.
     *
     * @param int $maxBodyBytes Maximum accepted body size in bytes (default 1 MiB).
     *
     * @return self
     *
     * @throws HttpException 400 on a malformed URI or JSON body, 413 when the body is too large.
     */
    public static function fromGlobals(int $maxBodyBytes = 1_048_576): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uri = '/' . ltrim((string) ($server['REQUEST_URI'] ?? '/'), '/');
        $rawPath = parse_url($uri, PHP_URL_PATH);
        if (!is_string($rawPath) || str_contains($rawPath, "\0")) {
            throw new HttpException(400, 'Malformed request URI.');
        }

        $decoded = rawurldecode($rawPath);
        if (str_contains($decoded, "\0")) {
            throw new HttpException(400, 'Malformed request URI.');
        }

        // Strip "/index.php" or the sub-directory the app is installed in.
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base === '.') {
            $base = '';
        }

        if ($script !== '' && ($decoded === $script || str_starts_with($decoded, $script . '/'))) {
            $decoded = substr($decoded, strlen($script));
        } elseif ($base !== '' && ($decoded === $base || str_starts_with($decoded, $base . '/'))) {
            $decoded = substr($decoded, strlen($base));
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        if (!isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $raw = '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            if ((int) ($headers['content-length'] ?? 0) > $maxBodyBytes) {
                throw new HttpException(413);
            }
            $raw = self::readInput($maxBodyBytes);
        }

        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $secure = ($https !== '' && $https !== 'off') || (int) ($server['SERVER_PORT'] ?? 0) === 443;

        return new self(
            $method,
            Path::normalize($decoded),
            $_GET,
            self::parseBody($method, $headers, $raw),
            $headers,
            $_COOKIE,
            $_FILES,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $secure,
            $base,
            $raw,
        );
    }

    /**
     * Parses the request body according to its content type.
     *
     * @param string               $method  HTTP method.
     * @param array<string,string> $headers Lower-cased headers.
     * @param string               $raw     Raw request body.
     *
     * @return array<string,mixed>
     *
     * @throws HttpException On invalid JSON.
     */
    private static function parseBody(string $method, array $headers, string $raw): array
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return [];
        }

        $type = strtolower($headers['content-type'] ?? '');

        if (str_contains($type, 'application/json')) {
            if ($raw === '') {
                return [];
            }
            try {
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException(400, 'Invalid JSON body.');
            }
            if (!is_array($data)) {
                throw new HttpException(400, 'JSON body must be an object or an array.');
            }

            return $data;
        }

        if (str_contains($type, 'multipart/form-data')) {
            return $_POST;
        }

        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            if ($method === 'POST') {
                return $_POST;
            }
            parse_str($raw, $parsed);

            return $parsed;
        }

        return [];
    }

    /**
     * Reads the raw body from php://input, enforcing the size limit.
     *
     * @param int $max Maximum bytes.
     *
     * @return string
     *
     * @throws HttpException 413 when the body exceeds the limit.
     */
    private static function readInput(int $max): string
    {
        $raw = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($raw === false) {
            return '';
        }
        if (strlen($raw) > $max) {
            throw new HttpException(413);
        }

        return $raw;
    }

    /**
     * @return string Upper-case HTTP method.
     */
    public function method(): string
    {
        return strtoupper($this->method);
    }

    /**
     * @return string Normalized path relative to the app base path (e.g. "/users/42").
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return string Sub-directory the app is installed in ("" at the web root).
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Returns the raw request body exactly as received.
     *
     * Signature checks (Stripe, Buy Me a Coffee...) must use these bytes, never a re-encoded body.
     *
     * @return string
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Reads query-string values.
     *
     * @param string|null $key     Key to read, or null for all values.
     * @param mixed       $default Fallback when the key is missing.
     *
     * @return mixed
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /**
     * Reads parsed body values.
     *
     * @param string|null $key     Key to read, or null for the whole body.
     * @param mixed       $default Fallback when the key is missing.
     *
     * @return mixed
     */
    public function body(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    /**
     * Reads a value from the body, then the query string, then route params.
     *
     * @param string $key     Key to read.
     * @param mixed  $default Fallback when the key is missing.
     *
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $this->params[$key] ?? $default;
    }

    /**
     * Returns a route parameter (e.g. `:id`).
     *
     * @param string $name    Parameter name.
     * @param mixed  $default Fallback when missing.
     *
     * @return mixed
     */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @return array<string,string> All route parameters.
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Sets route parameters (called by the router).
     *
     * @param array<string,string> $params Captured parameters.
     *
     * @return static
     */
    public function setParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Reads a request header (case-insensitive).
     *
     * @param string      $name    Header name.
     * @param string|null $default Fallback when missing.
     *
     * @return string|null
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string,string> All headers, with lower-cased names.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Reads a cookie.
     *
     * @param string      $name    Cookie name.
     * @param string|null $default Fallback when missing.
     *
     * @return string|null
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Returns an uploaded file entry from `$_FILES`.
     *
     * @param string $name Form field name.
     *
     * @return array<string,mixed>|null
     */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;

        return is_array($file) ? $file : null;
    }

    /**
     * @return string Client IP address (REMOTE_ADDR only).
     */
    public function ip(): string
    {
        return $this->ip;
    }

    /**
     * @return bool True when served over HTTPS.
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * @return bool True when the request body is JSON.
     */
    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '') ?? ''), 'json');
    }

    /**
     * @return bool True when the client sent or asks for JSON.
     */
    public function wantsJson(): bool
    {
        return $this->isJson() || str_contains(strtolower($this->header('accept', '') ?? ''), 'json');
    }

    /**
     * Extracts a Bearer token from the Authorization header.
     *
     * @return string|null
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Stores a per-request value, handy for passing data between middleware.
     *
     * @param string $key   Attribute name.
     * @param mixed  $value Attribute value.
     *
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Reads a per-request value stored with {@see Request::set()}.
     *
     * @param string $key     Attribute name.
     * @param mixed  $default Fallback when missing.
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
