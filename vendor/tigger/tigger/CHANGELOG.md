# Changelog

## 1.0.0

### Added
- **Views**: `$app->views($dir, $layout)`, `$res->render('home', $data)`, layouts, partials, shared variables, safe path resolution (no `../`).
- **Custom error pages**: `$app->errorPage(404, 'errors/404')`, `errorPage(500, ...)`; JSON clients keep JSON errors.
- **XSS protection**: `Tigger\Security\Escape` (`html`, `url`, `safeUrl`, `json`), `$this->e()` in views, `ContentSecurityPolicy` middleware with per-request nonce.
- **CSRF protection**: `Csrf` middleware (signed double-submit cookie + `Origin` / `Sec-Fetch-Site` checks).
- **Inbound webhooks**: `Webhook::receive()` with `StripeVerifier` (timestamp tolerance, secret rotation), `HmacVerifier` (Buy Me a Coffee, GitHub, generic), idempotency via `FileEventStore`.
- **Outbound webhooks**: `Dispatcher` (signed JSON events, wildcard subscriptions, retries, SSRF protection).
- `$app->terminating()` to run work after the response is sent.
- `Request::rawBody()`, `Response::local()`, `Response::getCookies()`.

## 0.1.0
- Router, middleware pipeline, request/response, `SecureHeaders`, `Cors`.
