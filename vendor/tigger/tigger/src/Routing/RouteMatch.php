<?php

declare(strict_types=1);

namespace Tigger\Routing;

use Closure;

/**
 * The result of a successful route lookup.
 */
final class RouteMatch
{
    /**
     * @param Route                              $route      The matched route.
     * @param array<string,string>               $params     Captured path parameters.
     * @param list<array{0: string, 1: Closure}> $middleware Router-level middleware as [prefix, middleware] pairs.
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $params,
        public readonly array $middleware,
    ) {
    }
}
