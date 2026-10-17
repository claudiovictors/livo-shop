<?php

declare(strict_types=1);

namespace Tigger\Routing;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Collects routes, router-level middleware and mounted sub-routers.
 *
 * Use it standalone to group routes (like `express.Router()`) and mount it
 * with `$app->use('/prefix', $router)`.
 */
final class Router
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    private const MAX_DEPTH = 16;

    /** @var list<Route> */
    private array $routes = [];

    /** @var list<array{0: string, 1: Closure}> */
    private array $uses = [];

    /** @var list<array{0: string, 1: Router}> */
    private array $mounts = [];

    /** @var list<array{route: Route, middleware: list<array{0: string, 1: Closure}>}>|null */
    private ?array $flat = null;

    /**
     * Registers a route for one or more methods.
     *
     * @param string|list<string> $methods HTTP method(s).
     * @param string              $path    Path pattern.
     * @param callable            $handler Route handler.
     *
     * @return Route
     *
     * @throws InvalidArgumentException If a method is not supported.
     */
    public function route(string|array $methods, string $path, callable $handler): Route
    {
        $methods = array_values(array_map('strtoupper', (array) $methods));

        foreach ($methods as $method) {
            if (!in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException("Unsupported HTTP method \"{$method}\".");
            }
        }

        $route = new Route($methods, $path, $handler);
        $this->routes[] = $route;
        $this->flat = null;

        return $route;
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function get(string $path, callable $handler): Route
    {
        return $this->route('GET', $path, $handler);
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function post(string $path, callable $handler): Route
    {
        return $this->route('POST', $path, $handler);
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function put(string $path, callable $handler): Route
    {
        return $this->route('PUT', $path, $handler);
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function patch(string $path, callable $handler): Route
    {
        return $this->route('PATCH', $path, $handler);
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function delete(string $path, callable $handler): Route
    {
        return $this->route('DELETE', $path, $handler);
    }

    /**
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function options(string $path, callable $handler): Route
    {
        return $this->route('OPTIONS', $path, $handler);
    }

    /**
     * Registers a route for every supported method.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function all(string $path, callable $handler): Route
    {
        return $this->route(self::METHODS, $path, $handler);
    }

    /**
     * Adds router-level middleware or mounts a sub-router.
     *
     * Forms: `use($middleware)`, `use('/prefix', $middleware)`,
     * `use('/prefix', $router)`. Router-level middleware runs before the
     * route's own middleware, regardless of registration order.
     *
     * @param string|callable|Router $pathOrHandler Path prefix, middleware or router.
     * @param callable|Router|null   $handler       Middleware or router when a path was given.
     *
     * @return static
     *
     * @throws InvalidArgumentException If no middleware/router was provided.
     */
    public function use(string|callable|Router $pathOrHandler, callable|Router|null $handler = null): static
    {
        if (is_string($pathOrHandler)) {
            $path = $pathOrHandler;
            $target = $handler;
        } else {
            $path = '';
            $target = $pathOrHandler;
        }

        if ($target === null) {
            throw new InvalidArgumentException('A middleware or a router is required.');
        }

        if ($target === $this) {
            throw new InvalidArgumentException('A router cannot be mounted on itself.');
        }

        if ($target instanceof Router) {
            $this->mounts[] = [$path, $target];
        } else {
            $this->uses[] = [$path, $target(...)];
        }

        $this->flat = null;

        return $this;
    }

    /**
     * Finds the route for a method and path.
     *
     * HEAD requests fall back to GET routes.
     *
     * @param string $method Upper-case HTTP method.
     * @param string $path   Normalized request path.
     *
     * @return RouteMatch|null
     */
    public function match(string $method, string $path): ?RouteMatch
    {
        foreach ($this->entries() as $entry) {
            $route = $entry['route'];
            $methods = $route->methods();

            if (!in_array($method, $methods, true) && !($method === 'HEAD' && in_array('GET', $methods, true))) {
                continue;
            }

            $params = $route->matchPath($path);
            if ($params !== null) {
                return new RouteMatch($route, $params, $entry['middleware']);
            }
        }

        return null;
    }

    /**
     * Lists the methods allowed for a path (used for 405 responses).
     *
     * @param string $path Normalized request path.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach ($this->entries() as $entry) {
            if ($entry['route']->matchPath($path) === null) {
                continue;
            }
            foreach ($entry['route']->methods() as $method) {
                $allowed[$method] = true;
                if ($method === 'GET') {
                    $allowed['HEAD'] = true;
                }
            }
        }

        return array_keys($allowed);
    }

    /**
     * Generates the URL path of a named route.
     *
     * @param string               $name   Route name.
     * @param array<string,scalar> $params Path parameter values.
     *
     * @return string
     *
     * @throws InvalidArgumentException If the route name is unknown.
     */
    public function urlFor(string $name, array $params = []): string
    {
        foreach ($this->entries() as $entry) {
            if ($entry['route']->getName() === $name) {
                return $entry['route']->url($params);
            }
        }

        throw new InvalidArgumentException("No route named \"{$name}\".");
    }

    /**
     * Flattens this router and its mounts into prefixed routes.
     *
     * @param string                             $prefix    Accumulated path prefix.
     * @param list<array{0: string, 1: Closure}> $inherited Middleware inherited from parent routers.
     * @param int                                $depth     Current nesting depth.
     *
     * @return list<array{route: Route, middleware: list<array{0: string, 1: Closure}>}>
     *
     * @throws LogicException If routers are nested too deeply (likely a cycle).
     */
    private function flatten(string $prefix = '', array $inherited = [], int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new LogicException('Router nesting is too deep (possible cycle).');
        }

        $chain = $inherited;
        foreach ($this->uses as [$path, $middleware]) {
            $chain[] = [Path::join($prefix, $path), $middleware];
        }

        $out = [];
        foreach ($this->routes as $route) {
            $out[] = ['route' => $route->withPrefix($prefix), 'middleware' => $chain];
        }

        foreach ($this->mounts as [$path, $child]) {
            array_push($out, ...$child->flatten(Path::join($prefix, $path), $chain, $depth + 1));
        }

        return $out;
    }

    /**
     * @return list<array{route: Route, middleware: list<array{0: string, 1: Closure}>}>
     */
    private function entries(): array
    {
        return $this->flat ??= $this->flatten();
    }
}
