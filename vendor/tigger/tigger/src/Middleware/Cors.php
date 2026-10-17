<?php

declare(strict_types=1);

namespace Tigger\Middleware;

use InvalidArgumentException;
use Tigger\Http\Request;
use Tigger\Http\Response;

/**
 * Cross-Origin Resource Sharing middleware (answers preflight requests itself).
 *
 * Options: `origins` (list, default `['*']`), `methods`, `headers`,
 * `credentials` (bool) and `maxAge` (seconds). A wildcard origin cannot be
 * combined with credentials.
 */
final class Cors
{
    /** @var list<string> */
    private array $origins;

    /** @var list<string> */
    private array $methods;

    /** @var list<string> */
    private array $allowedHeaders;

    private bool $credentials;

    private int $maxAge;

    /**
     * @param array{origins?: list<string>, methods?: list<string>, headers?: list<string>, credentials?: bool, maxAge?: int} $options CORS options.
     *
     * @throws InvalidArgumentException If a wildcard origin is combined with credentials.
     */
    public function __construct(array $options = [])
    {
        $this->origins = $options['origins'] ?? ['*'];
        $this->methods = $options['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $this->allowedHeaders = $options['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'];
        $this->credentials = $options['credentials'] ?? false;
        $this->maxAge = $options['maxAge'] ?? 600;

        if ($this->credentials && in_array('*', $this->origins, true)) {
            throw new InvalidArgumentException('A wildcard CORS origin cannot be combined with credentials.');
        }
    }

    /**
     * @param Request  $req  Current request.
     * @param Response $res  Current response.
     * @param callable $next Continues the chain.
     *
     * @return void
     */
    public function __invoke(Request $req, Response $res, callable $next): void
    {
        $origin = $req->header('origin');

        if ($origin === null) {
            $next();

            return;
        }

        $allowed = in_array('*', $this->origins, true)
            ? '*'
            : (in_array($origin, $this->origins, true) ? $origin : null);

        if ($allowed === null) {
            $next();

            return;
        }

        $res->header('Access-Control-Allow-Origin', $allowed);
        if ($allowed !== '*') {
            $res->header('Vary', 'Origin');
        }
        if ($this->credentials) {
            $res->header('Access-Control-Allow-Credentials', 'true');
        }

        $preflight = $req->method() === 'OPTIONS' && $req->header('access-control-request-method') !== null;
        if ($preflight) {
            $res->header('Access-Control-Allow-Methods', implode(', ', $this->methods))
                ->header('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
                ->header('Access-Control-Max-Age', $this->maxAge)
                ->noContent();

            return;
        }

        $next();
    }
}
