<?php

declare(strict_types=1);

namespace Tigger;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Throwable;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Routing\Path;
use Tigger\Routing\Route;
use Tigger\Routing\Router;
use Tigger\View\View;

/**
 * The Tigger application: your `server.js`.
 *
 * ```php
 * $app = new Tigger\App();
 * $app->get('/', fn ($req, $res) => $res->json(['message' => 'Hello, World!']));
 * $app->run();
 * ```
 */
final class App
{
    public const VERSION = '1.0.0';

    private Router $router;

    /** @var list<array{0: string, 1: Closure}> */
    private array $globals = [];

    private ?Closure $errorHandler = null;

    private string $basePath = '';

    private ?View $views = null;

    /** @var array<int,string> */
    private array $errorPages = [];

    /** @var list<Closure> */
    private array $terminators = [];

    /**
     * @param bool $debug When true, error responses include exception details.
     *                    When false, PHP's `display_errors` is switched off so internals never leak.
     */
    public function __construct(private readonly bool $debug = false)
    {
        $this->router = new Router();

        if (!$debug) {
            ini_set('display_errors', '0');
        }
    }

    /**
     * Registers global middleware or mounts a router.
     *
     * Forms: `use($mw)`, `use('/prefix', $mw)`, `use('/prefix', $router)`.
     * Global middleware runs for every request, even when no route matches.
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

        if ($target instanceof Router) {
            $this->router->use($path, $target);
        } else {
            $this->globals[] = [$path, $target(...)];
        }

        return $this;
    }

    /**
     * Registers a GET route (also answers HEAD).
     *
     * @param string   $path    Path pattern, e.g. "/users/:id".
     * @param callable $handler Handler `function (Request $req, Response $res)`.
     *
     * @return Route Chain `->middleware()`, `->name()` or `->where()`.
     */
    public function get(string $path, callable $handler): Route
    {
        return $this->router->get($path, $handler);
    }

    /**
     * Registers a POST route.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function post(string $path, callable $handler): Route
    {
        return $this->router->post($path, $handler);
    }

    /**
     * Registers a PUT route.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function put(string $path, callable $handler): Route
    {
        return $this->router->put($path, $handler);
    }

    /**
     * Registers a PATCH route.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function patch(string $path, callable $handler): Route
    {
        return $this->router->patch($path, $handler);
    }

    /**
     * Registers a DELETE route.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function delete(string $path, callable $handler): Route
    {
        return $this->router->delete($path, $handler);
    }

    /**
     * Registers an OPTIONS route.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function options(string $path, callable $handler): Route
    {
        return $this->router->options($path, $handler);
    }

    /**
     * Registers a route for every HTTP method.
     *
     * @param string   $path    Path pattern.
     * @param callable $handler Route handler.
     *
     * @return Route
     */
    public function all(string $path, callable $handler): Route
    {
        return $this->router->all($path, $handler);
    }

    /**
     * Configures the views directory used by `$res->render()` and custom error pages.
     *
     * @param string      $directory Views directory (must exist), e.g. `__DIR__ . '/views'`.
     *                               Keep it outside the web root, or block direct access with .htaccess.
     * @param string|null $layout    Default layout applied to every render (see `$this->layout()` in views).
     * @param string      $extension Template file extension.
     *
     * @return View The engine; use `->share('key', $value)` to expose variables to every template.
     *
     * @throws InvalidArgumentException If the directory does not exist.
     */
    public function views(string $directory, ?string $layout = null, string $extension = '.php'): View
    {
        return $this->views = new View($directory, $extension, $layout);
    }

    /**
     * Uses a view as the response page for an HTTP error status (e.g. 404, 403, 500).
     *
     * The template receives `$status`, `$message`, `$title`, `$debug` (bool) and `$exception`
     * (only when debug is on, otherwise null). Statuses >= 500 fall back to the 500 page.
     * JSON clients still get the JSON error body.
     *
     * @param int    $status   HTTP status code.
     * @param string $template Template name, e.g. "errors/404".
     *
     * @return static
     */
    public function errorPage(int $status, string $template): static
    {
        $this->errorPages[$status] = $template;

        return $this;
    }

    /**
     * Registers work to run after the response has been sent (sending webhooks, emails, logging).
     *
     * With PHP-FPM the connection is closed first, so the visitor never waits for it.
     * Callbacks receive `(Request $req, Response $res)`; exceptions are logged, never shown.
     *
     * @param callable $callback Callback to run after the response.
     *
     * @return static
     */
    public function terminating(callable $callback): static
    {
        $this->terminators[] = $callback(...);

        return $this;
    }

    /**
     * Installs a custom error handler (also receives 404/405 {@see HttpException}s).
     *
     * @param callable $handler `function (Throwable $e, Request $req, Response $res): void`.
     *
     * @return static
     */
    public function onError(callable $handler): static
    {
        $this->errorHandler = $handler(...);

        return $this;
    }

    /**
     * Generates the URL of a named route, including the app's base path.
     *
     * @param string               $name   Route name set with `->name()`.
     * @param array<string,scalar> $params Path parameter values.
     * @param array<string,mixed>  $query  Optional query-string values.
     *
     * @return string
     *
     * @throws InvalidArgumentException If the route is unknown or a parameter is missing.
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $url = $this->basePath . $this->router->urlFor($name, $params);

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * Handles the current HTTP request and sends the response.
     *
     * @param Request|null $request Request to handle; built from PHP globals when omitted.
     *
     * @return void
     */
    public function run(?Request $request = null): void
    {
        if ($request === null) {
            try {
                $request = Request::fromGlobals();
            } catch (HttpException $e) {
                $request = new Request('GET', '/');
                $response = $this->newResponse();
                $this->handleError($e, $request, $response);
                $response->flush();

                return;
            }
        }

        $response = $this->handle($request);
        $response->flush($request->method() === 'HEAD');
        $this->terminate($request, $response);
    }

    /**
     * Runs a request through middleware and routing and returns the response without sending it.
     * Useful for tests.
     *
     * @param Request $request The request to handle.
     *
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $this->basePath = $request->basePath();
        $response = $this->newResponse();

        try {
            $stack = [];
            foreach ($this->globals as [$prefix, $middleware]) {
                if (Path::matchesPrefix($prefix, $request->path())) {
                    $stack[] = $middleware;
                }
            }

            Pipeline::run($stack, $request, $response, fn () => $this->dispatch($request, $response));
        } catch (Throwable $e) {
            $this->handleError($e, $request, $response);
        }

        return $response;
    }

    /**
     * Creates a response wired to the view engine.
     *
     * @return Response
     */
    private function newResponse(): Response
    {
        return (new Response())->setViews($this->views);
    }

    /**
     * Runs the callbacks registered with {@see App::terminating()}.
     *
     * @param Request  $request  Handled request.
     * @param Response $response Sent response.
     *
     * @return void
     */
    private function terminate(Request $request, Response $response): void
    {
        if ($this->terminators === []) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($this->terminators as $callback) {
            try {
                $callback($request, $response);
            } catch (Throwable $e) {
                error_log('[Tigger] terminating callback failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Finds the route and runs its middleware and handler.
     *
     * @param Request  $request  Current request.
     * @param Response $response Current response.
     *
     * @return void
     *
     * @throws HttpException 404 when no route matches, 405 when the path exists for other methods.
     */
    private function dispatch(Request $request, Response $response): void
    {
        $match = $this->router->match($request->method(), $request->path());

        if ($match === null) {
            $allowed = $this->router->allowedMethods($request->path());
            if ($allowed !== []) {
                throw new HttpException(405, '', ['Allow' => implode(', ', $allowed)]);
            }

            throw new HttpException(404);
        }

        $request->setParams($match->params);

        $stack = [];
        foreach ($match->middleware as [$prefix, $middleware]) {
            if (Path::matchesPrefix($prefix, $request->path())) {
                $stack[] = $middleware;
            }
        }
        array_push($stack, ...$match->route->getMiddleware());

        $handler = $match->route->getHandler();

        Pipeline::run($stack, $request, $response, function () use ($handler, $request, $response): void {
            $this->normalize($handler($request, $response), $response);
        });
    }

    /**
     * Turns a handler's return value into a response when it did not respond itself.
     *
     * Arrays, objects and JsonSerializable values become JSON; strings become HTML.
     *
     * @param mixed    $result   Handler return value.
     * @param Response $response Current response.
     *
     * @return void
     */
    private function normalize(mixed $result, Response $response): void
    {
        if ($response->isFinished() || $result === null || $result instanceof Response) {
            return;
        }

        if (is_string($result)) {
            $response->send($result);
        } elseif (is_array($result) || is_object($result) || $result instanceof JsonSerializable) {
            $response->json($result);
        }
    }

    /**
     * Converts a throwable into an error response using the custom or default handler.
     *
     * @param Throwable $e        What was thrown.
     * @param Request   $request  Current request.
     * @param Response  $response Response to rewrite.
     *
     * @return void
     */
    private function handleError(Throwable $e, Request $request, Response $response): void
    {
        $response->resetBody();

        if (!$e instanceof HttpException || $e->getStatusCode() >= 500) {
            error_log(sprintf('[Tigger] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        }

        if ($this->errorHandler !== null) {
            try {
                ($this->errorHandler)($e, $request, $response);
                if ($response->isFinished()) {
                    return;
                }
            } catch (Throwable $inner) {
                error_log('[Tigger] Error handler failed: ' . $inner->getMessage());
            }
            $response->resetBody();
        }

        $this->renderDefaultError($e, $request, $response);
    }

    /**
     * Writes the built-in JSON or HTML error response.
     *
     * @param Throwable $e        What was thrown.
     * @param Request   $request  Current request.
     * @param Response  $response Response to write.
     *
     * @return void
     */
    private function renderDefaultError(Throwable $e, Request $request, Response $response): void
    {
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();

            foreach ($e->getHeaders() as $name => $value) {
                $response->header($name, $value);
            }
        } else {
            $status = 500;
            $message = $this->debug ? $e->getMessage() : HttpException::phrase(500);
        }

        $response->status($status);

        if ($request->wantsJson()) {
            $error = ['status' => $status, 'message' => $message];
            if ($this->debug) {
                $error['debug'] = [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ];
            }
            $response->json(['error' => $error]);

            return;
        }

        $page = $this->errorPages[$status] ?? ($status >= 500 ? ($this->errorPages[500] ?? null) : null);
        if ($page !== null && $this->views !== null) {
            try {
                $response->render($page, [
                    'status' => $status,
                    'message' => $message,
                    'title' => $status . ' ' . HttpException::phrase($status),
                    'debug' => $this->debug,
                    'exception' => $this->debug ? $e : null,
                ]);

                return;
            } catch (Throwable $inner) {
                error_log('[Tigger] Error page failed: ' . $inner->getMessage());
                $response->resetBody();
            }
        }

        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $status . ' ' . htmlspecialchars(HttpException::phrase($status), ENT_QUOTES, 'UTF-8');
        $details = '';
        if ($this->debug) {
            $trace = htmlspecialchars($e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $details = '<pre>' . $trace . '</pre>';
        }

        $response->html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head>'
            . '<body><h1>' . $title . '</h1><p>' . $safe . '</p>' . $details . '</body></html>'
        );
    }
}
