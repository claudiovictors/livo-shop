<?php

declare(strict_types=1);

namespace Tigger\Middleware;

use Tigger\Http\Request;
use Tigger\Http\Response;

/**
 * Adds a conservative set of security headers to every response.
 *
 * Pass overrides to change a header, or `null`/`false` to drop one:
 * `new SecureHeaders(['X-Frame-Options' => 'DENY', 'Referrer-Policy' => null])`.
 * HSTS is only sent over HTTPS.
 */
final class SecureHeaders
{
    /** @var array<string, string|null|false> */
    private array $headers;

    /**
     * @param array<string, string|null|false> $overrides Header overrides.
     */
    public function __construct(array $overrides = [])
    {
        $this->headers = array_replace([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ], $overrides);
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
        foreach ($this->headers as $name => $value) {
            if ($value !== null && $value !== false) {
                $res->header($name, $value);
            }
        }

        if ($req->isSecure()) {
            $res->header('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        $next();
    }
}
