# Tigger

A tiny, Express.js-inspired micro-framework for **PHP 8.3+** with **zero dependencies**.
Router, middleware, views, **CSRF/XSS protection**, **webhooks** (incoming and outgoing) —
built to run on any Apache host, including free shared hosting.

```bash
composer require tigger/tigger
```

## Quick start

`index.php` in your project root plays the role of `server.js`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;

$app = new App();

$app->use(function (Request $req, Response $res, callable $next): void {
    $next(); // global middleware
});

$app->get('/', function (Request $req, Response $res): void {
    $res->json(['message' => 'Hello, World!']);
});

$app->get('/users/:id', fn (Request $req, Response $res) => $res->json(['id' => $req->param('id')]))
    ->where('id', '[0-9]+')
    ->middleware($auth)
    ->name('users.show');

$app->run();
```

Copy `examples/.htaccess` next to `index.php`. For local development use `php -S localhost:8000`
**without** passing `index.php` (so real files such as CSS are served directly).

## Handlers, middleware, router

* Handler: `function (Request $req, Response $res): mixed` — respond through `$res`, or **return** an array/object (JSON) or a string (HTML). A `: void` handler must not `return` a value.
* Middleware: `function (Request $req, Response $res, callable $next): void` — call `$next()` to continue.
* Patterns: `/users/:id`, `/files/*` (captured as `wildcard`); constrain with `->where('id', '[0-9]+')`. Unknown path → 404, other method → 405 + `Allow`.
* Group routes like `express.Router()`:

```php
$api = new Tigger\Routing\Router();
$api->get('/ping', fn () => ['pong' => true]);
$app->use('/api', $api);
```

## Views, layouts and custom error pages

```php
$app->views(__DIR__ . '/views', layout: 'layouts/main')->share('shop', 'Life Shop');

$app->get('/', fn ($req, $res) => $res->render('home', ['name' => 'Ana']));      // views/home.php
$res->render('checkout/step1', $data, status: 200, layout: 'layouts/plain');     // a specific file, own layout

$app->errorPage(404, 'errors/404');   // views/errors/404.php
$app->errorPage(500, 'errors/500');   // also used for any other 5xx
```

Inside a view `$this` is a template helper:

```php
<h1><?= $this->e($title) ?></h1>        <!-- HTML-escaped -->
<?php $this->partial('partials/menu') ?>
<?php $this->layout('layouts/admin') ?>  <!-- optional per-view layout; output goes to $content -->
```

Error templates receive `$status`, `$message`, `$title`, `$debug` and `$exception` (only when debug is on).
JSON clients (`Accept: application/json`) still receive a JSON error body. For full control use `$app->onError(fn (Throwable $e, Request $req, Response $res) => ...)`.

Template names are resolved inside the views directory only (`../` and absolute paths are rejected).
**Keep views outside the web root** (or use the provided `.htaccess`, which blocks `/public/*.php`).

## Security

| Threat | What Tigger gives you |
|---|---|
| **XSS** | `$this->e()` / `Escape::html()` for text and quoted attributes, `Escape::safeUrl()` (blocks `javascript:`/`data:`), `Escape::json()` for data inside `<script>`, and a nonce-based `ContentSecurityPolicy` middleware so injected inline scripts do not run. |
| **CSRF** | `Csrf` middleware: signed double-submit cookie (`HttpOnly`, `SameSite=Lax`, `__Host-` over HTTPS) + `Origin` and `Sec-Fetch-Site` checks. |
| **Header injection** | CR/LF rejected in header and cookie values. |
| **Clickjacking, sniffing, referrer leaks** | `SecureHeaders` (nosniff, frame options, referrer policy, HSTS over HTTPS). |
| **SSRF** | Outgoing webhooks: HTTPS only, public IPs only, connection pinned to the checked IP, no redirects. |
| **Replay / forgery of webhooks** | Constant-time HMAC check on the raw body, timestamp tolerance (Stripe), idempotency store. |
| **Information leaks** | `debug: false` hides internals and turns off `display_errors`. |
| **Path traversal** | `render()` cannot leave the views directory. |

```php
$app->use(new SecureHeaders());
$app->use(new ContentSecurityPolicy([
    'script-src' => ["'self'", "'nonce-{nonce}'", 'https://js.stripe.com'],
    'frame-src'  => ['https://js.stripe.com'],
]));
$app->use(new Csrf($secret, except: ['/webhooks', '/api']));   // Csrf::generateSecret() makes a secret
```

```php
<form method="post"><?= $this->csrfField() ?> ...</form>
<script nonce="<?= $this->nonce() ?>">const t = <?= $this->json($data) ?>;</script>
fetch('/pay', { method: 'POST', headers: { 'X-CSRF-Token': '<?= $this->csrfToken() ?>' } });
```

Exempt (`except`) only endpoints that authenticate in another way: signed webhooks, bearer-token APIs.
Always serve your shop over **HTTPS**, and never commit secrets: read them from environment variables or a file outside the web root.

## Webhooks

### Receiving (Stripe, Buy Me a Coffee, any HMAC provider)

```php
$app->post('/webhooks/stripe', Webhook::receive(
    Webhook::stripe($whsec),                       // or Webhook::buyMeACoffee($secret) / Webhook::hmac(...)
    function (WebhookEvent $event, Request $req, Response $res): void {
        if ($event->type === 'checkout.session.completed') {
            // fulfil the order using $event->data (Stripe's data.object)
        }
    },
    new FileEventStore(__DIR__ . '/storage/webhooks'),   // ignore duplicate deliveries
));
```

The signature is checked against the **raw body** (`$req->rawBody()`), in constant time, before anything else.
If your handler throws, the claim is released and a 500 is returned so the provider retries; if it does not
respond, `200 {"received":true}` is sent. Secret rotation: pass an array of secrets.

### Sending (to Zapier, n8n, Make, Slack, Discord, your own tools)

```php
$hooks = new Dispatcher(secret: $secret, retries: 2);
$hooks->subscribe('order.*', 'https://hooks.zapier.com/hooks/catch/...');
$hooks->subscribe('*', 'https://example.com/tigger-events', secret: 'other-secret');

$app->terminating(fn () => $hooks->emit('order.paid', ['order' => 42, 'total' => 5000]));  // after the response
$hooks->sendRaw($slackUrl, ['text' => 'Nova venda: 5 000 Kz']);                              // custom body
```

Each delivery is `{"id","type","created","data"}` with `X-Tigger-Event`, `X-Tigger-Event-Id`, `X-Tigger-Timestamp`
and `X-Tigger-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">`. Requires the cURL extension.

## Tests

```bash
composer test
```

## License

MIT
