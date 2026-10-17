<?php

declare(strict_types=1);

namespace Tigger\Routing;

use Closure;
use InvalidArgumentException;

/**
 * A single route: HTTP methods + path pattern + handler (+ middleware and name).
 *
 * Path patterns support named parameters (`/users/:id`) and a trailing
 * wildcard (`/files/*`, captured as the `wildcard` parameter).
 */
final class Route
{
    private const PARAM_NAME = '[A-Za-z_][A-Za-z0-9_]*';

    private Closure $handler;

    /** @var list<Closure> */
    private array $middleware = [];

    private ?string $name = null;

    /** @var array<string,string> */
    private array $constraints = [];

    private string $prefix = '';

    private ?string $regex = null;

    /**
     * @param list<string> $methods Upper-case HTTP methods this route answers.
     * @param string       $path    Path pattern, e.g. "/users/:id".
     * @param callable     $handler Handler `function (Request $req, Response $res): mixed`.
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $path,
        callable $handler,
    ) {
        $this->handler = $handler(...);
    }

    /**
     * Attaches route-level middleware, run before the handler.
     *
     * @param callable ...$middleware Middleware `function (Request $req, Response $res, callable $next): void`.
     *
     * @return static
     */
    public function middleware(callable ...$middleware): static
    {
        foreach ($middleware as $mw) {
            $this->middleware[] = $mw(...);
        }

        return $this;
    }

    /**
     * Names the route so its URL can be generated with `$app->url()`.
     *
     * @param string $name Unique route name.
     *
     * @return static
     *
     * @throws InvalidArgumentException If the name is empty.
     */
    public function name(string $name): static
    {
        if ($name === '') {
            throw new InvalidArgumentException('Route name cannot be empty.');
        }

        $this->name = $name;

        return $this;
    }

    /**
     * Restricts a path parameter with a regular expression (without delimiters).
     *
     * @param string $param   Parameter name, e.g. "id".
     * @param string $pattern Regex fragment, e.g. "[0-9]+".
     *
     * @return static
     *
     * @throws InvalidArgumentException If the name or pattern is invalid.
     */
    public function where(string $param, string $pattern): static
    {
        if (preg_match('/^' . self::PARAM_NAME . '$/', $param) !== 1) {
            throw new InvalidArgumentException("Invalid parameter name \"{$param}\".");
        }

        if (@preg_match('#^' . str_replace('#', '\#', $pattern) . '$#', '') === false) {
            throw new InvalidArgumentException("Invalid constraint pattern for \"{$param}\".");
        }

        $this->constraints[$param] = $pattern;
        $this->regex = null;

        return $this;
    }

    /**
     * Returns a copy of this route mounted under a prefix.
     *
     * @param string $prefix Path prefix.
     *
     * @return static
     */
    public function withPrefix(string $prefix): static
    {
        $copy = clone $this;
        $copy->prefix = Path::join($prefix, $this->prefix);
        $copy->regex = null;

        return $copy;
    }

    /**
     * @return list<string> HTTP methods handled by this route.
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * Returns the full (prefixed, normalized) path pattern.
     *
     * @return string
     */
    public function path(): string
    {
        return Path::join($this->prefix, $this->path);
    }

    /**
     * @return string|null The route name, if any.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return Closure The route handler.
     */
    public function getHandler(): Closure
    {
        return $this->handler;
    }

    /**
     * @return list<Closure> Route-level middleware.
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Matches a normalized request path against this route.
     *
     * @param string $path Normalized request path.
     *
     * @return array<string,string>|null Captured parameters, or null if no match.
     */
    public function matchPath(string $path): ?array
    {
        $this->regex ??= $this->compile();

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Builds a URL path for this route.
     *
     * @param array<string,scalar> $params Values for the path parameters.
     *
     * @return string
     *
     * @throws InvalidArgumentException If a required parameter is missing.
     */
    public function url(array $params = []): string
    {
        $segments = explode('/', trim($this->path(), '/'));
        $last = count($segments) - 1;
        $out = [];

        foreach ($segments as $i => $segment) {
            if ($segment === '*' && $i === $last) {
                $value = (string) ($params['wildcard'] ?? '');
                $out[] = implode('/', array_map('rawurlencode', explode('/', $value)));
            } elseif (str_starts_with($segment, ':')) {
                $name = substr($segment, 1);
                if (!array_key_exists($name, $params)) {
                    throw new InvalidArgumentException("Missing URL parameter \"{$name}\" for route \"{$this->path}\".");
                }
                $out[] = rawurlencode((string) $params[$name]);
            } else {
                $out[] = $segment;
            }
        }

        return '/' . implode('/', $out);
    }

    /**
     * Compiles the path pattern into a regular expression.
     *
     * @return string
     *
     * @throws InvalidArgumentException If the pattern contains an invalid parameter name.
     */
    private function compile(): string
    {
        $full = trim($this->path(), '/');

        if ($full === '') {
            return '#^/$#D';
        }

        $segments = explode('/', $full);
        $last = count($segments) - 1;
        $parts = [];

        foreach ($segments as $i => $segment) {
            if ($segment === '*' && $i === $last) {
                $parts[] = '(?P<wildcard>.*)';
            } elseif (str_starts_with($segment, ':')) {
                $name = substr($segment, 1);
                if (preg_match('/^' . self::PARAM_NAME . '$/', $name) !== 1) {
                    throw new InvalidArgumentException("Invalid parameter \"{$segment}\" in path \"{$this->path}\".");
                }
                $pattern = str_replace('#', '\#', $this->constraints[$name] ?? '[^/]+');
                $parts[] = '(?P<' . $name . '>' . $pattern . ')';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        return '#^/' . implode('/', $parts) . '$#D';
    }
}
