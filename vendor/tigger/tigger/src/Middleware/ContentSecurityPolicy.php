<?php

declare(strict_types=1);

namespace Tigger\Middleware;

use Tigger\Http\Request;
use Tigger\Http\Response;

/**
 * Sends a Content-Security-Policy header with a fresh nonce per request — a strong
 * second layer against XSS: injected inline scripts will not run.
 *
 * The nonce is available in templates as `$this->nonce()`:
 * `<script nonce="<?= $this->nonce() ?>">...</script>`.
 * Use the `{nonce}` placeholder in any directive. Directives you pass replace the
 * default for that directive; add the hosts you actually use (CDNs, payment widgets):
 *
 * ```php
 * new ContentSecurityPolicy([
 *     'script-src' => ["'self'", "'nonce-{nonce}'", 'https://js.stripe.com'],
 *     'frame-src'  => ['https://js.stripe.com'],
 * ]);
 * ```
 */
final class ContentSecurityPolicy
{
    /** @var array<string, list<string>> */
    private array $directives;

    /**
     * @param array<string, list<string>> $directives Directive name => sources.
     * @param bool                        $reportOnly Send as Report-Only (log violations without blocking).
     */
    public function __construct(array $directives = [], private readonly bool $reportOnly = false)
    {
        $this->directives = array_replace([
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{nonce}'"],
            'style-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ], $directives);
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
        $nonce = base64_encode(random_bytes(16));

        $policy = [];
        foreach ($this->directives as $name => $sources) {
            $policy[] = trim($name . ' ' . str_replace('{nonce}', $nonce, implode(' ', $sources)));
        }

        $res->header(
            $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            implode('; ', $policy)
        );

        $req->set('csp_nonce', $nonce);
        $res->local('cspNonce', $nonce);

        $next();
    }
}
