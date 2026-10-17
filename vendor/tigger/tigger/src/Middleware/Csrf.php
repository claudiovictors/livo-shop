<?php

declare(strict_types=1);

namespace Tigger\Middleware;

use InvalidArgumentException;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Routing\Path;

/**
 * CSRF protection using the OWASP "signed double-submit cookie" pattern,
 * plus `Origin` and `Sec-Fetch-Site` checks as defence in depth.
 *
 * - A random value lives in an HttpOnly, SameSite=Lax cookie (`__Host-` prefixed over HTTPS).
 * - The token embedded in forms is `HMAC(secret, cookie)`, so an attacker who can
 *   plant a cookie still cannot forge a matching token without the server secret.
 * - Unsafe methods (POST, PUT, PATCH, DELETE) must send the token in the `_csrf`
 *   body field or the `X-CSRF-Token` header.
 *
 * In templates use `<?= $this->csrfField() ?>` or `$this->csrfToken()` (AJAX).
 * Exempt endpoints that authenticate differently (signed webhooks, bearer-token APIs)
 * with the `except` option.
 */
final class Csrf
{
    private const FIELD = '_csrf';

    private const HEADER = 'x-csrf-token';

    /**
     * @param string       $secret         Random secret of at least 32 characters (see {@see Csrf::generateSecret()}).
     * @param list<string> $except         Path prefixes that skip the check, e.g. `['/webhooks', '/api']`.
     * @param list<string> $trustedOrigins Extra origins allowed to submit, e.g. `['https://admin.example.com']`.
     * @param string       $cookieName     Base cookie name.
     *
     * @throws InvalidArgumentException If the secret is too short.
     */
    public function __construct(
        private readonly string $secret,
        private readonly array $except = [],
        private readonly array $trustedOrigins = [],
        private readonly string $cookieName = 'tigger_csrf',
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The CSRF secret must have at least 32 characters.');
        }
    }

    /**
     * Generates a strong secret to store in your configuration.
     *
     * @return string 64 hexadecimal characters.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param Request  $req  Current request.
     * @param Response $res  Current response.
     * @param callable $next Continues the chain.
     *
     * @return void
     *
     * @throws HttpException 403 when the request fails validation.
     */
    public function __invoke(Request $req, Response $res, callable $next): void
    {
        $name = ($req->isSecure() ? '__Host-' : '') . $this->cookieName;
        $cookie = $req->cookie($name);
        $issued = false;

        if ($cookie === null || preg_match('/^[A-Za-z0-9_\-]{43}$/', $cookie) !== 1) {
            $cookie = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $issued = true;
        }

        $token = hash_hmac('sha256', $cookie, $this->secret);

        if (!in_array($req->method(), ['GET', 'HEAD', 'OPTIONS'], true) && !$this->isExcepted($req)) {
            $this->assertValid($req, $issued, $token);
        }

        if ($issued) {
            $res->cookie($name, $cookie, [
                'path' => '/',
                'secure' => $req->isSecure(),
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ]);
        }

        $req->set('csrf_token', $token);
        $res->local('csrfToken', $token);

        $next();
    }

    /**
     * Validates origin headers and the submitted token.
     *
     * @param Request $req    Current request.
     * @param bool    $issued True when the request carried no valid CSRF cookie.
     * @param string  $token  Expected token.
     *
     * @return void
     *
     * @throws HttpException 403 on any failure.
     */
    private function assertValid(Request $req, bool $issued, string $token): void
    {
        if (strtolower($req->header('sec-fetch-site', '') ?? '') === 'cross-site') {
            throw new HttpException(403, 'Cross-site request blocked.');
        }

        $origin = $req->header('origin');
        if ($origin !== null && !$this->isAllowedOrigin($origin, $req)) {
            throw new HttpException(403, 'Origin not allowed.');
        }

        $supplied = $req->header(self::HEADER) ?? $req->body(self::FIELD, '');

        if ($issued || !is_string($supplied) || $supplied === '' || !hash_equals($token, $supplied)) {
            throw new HttpException(403, 'Invalid or missing CSRF token.');
        }
    }

    /**
     * Tells whether an Origin header matches this host or a trusted origin.
     *
     * @param string  $origin Value of the Origin header.
     * @param Request $req    Current request.
     *
     * @return bool
     */
    private function isAllowedOrigin(string $origin, Request $req): bool
    {
        if (in_array($origin, $this->trustedOrigins, true)) {
            return true;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false; // includes the opaque "null" origin
        }

        $originHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $host = strtolower($req->header('host', '') ?? '');

        return $host !== '' && $originHost === $host;
    }

    /**
     * Tells whether the request path is exempt from CSRF checks.
     *
     * @param Request $req Current request.
     *
     * @return bool
     */
    private function isExcepted(Request $req): bool
    {
        foreach ($this->except as $prefix) {
            if (Path::matchesPrefix($prefix, $req->path())) {
                return true;
            }
        }

        return false;
    }
}
