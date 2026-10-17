<?php

declare(strict_types=1);

namespace Tigger;

use Closure;
use Tigger\Http\Request;
use Tigger\Http\Response;

/**
 * Runs a stack of middleware like Express does: each one receives
 * `$next` and decides whether the chain continues.
 *
 * @internal
 */
final class Pipeline
{
    private function __construct()
    {
    }

    /**
     * Executes the middleware stack, then the final handler.
     *
     * @param list<Closure> $stack Middleware `function (Request $req, Response $res, callable $next): void`.
     * @param Request       $request  Current request.
     * @param Response      $response Current response.
     * @param Closure       $final    Runs when every middleware called `$next()`.
     *
     * @return void
     */
    public static function run(array $stack, Request $request, Response $response, Closure $final): void
    {
        $runner = null;
        $runner = static function (int $index) use (&$runner, $stack, $request, $response, $final): void {
            if (!isset($stack[$index])) {
                $final();

                return;
            }

            $stack[$index]($request, $response, static fn () => $runner($index + 1));
        };

        $runner(0);
    }
}
