<?php

declare(strict_types=1);

// Run: php tests/features.php
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Tigger\\'))) . '.php';
    if (str_starts_with($class, 'Tigger\\') && is_file($file)) {
        require $file;
    }
});

use Tigger\App;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\ContentSecurityPolicy;
use Tigger\Middleware\Csrf;
use Tigger\Security\Escape;
use Tigger\Webhooks\Dispatcher;
use Tigger\Webhooks\FileEventStore;
use Tigger\Webhooks\Webhook;
use Tigger\Webhooks\WebhookEvent;

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . PHP_EOL;
    $fail += $ok ? 0 : 1;
};
$throws = static function (callable $fn, string $class = Throwable::class): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
};

echo "Escape / XSS\n";
$check('html escapes tags and quotes', Escape::html('<script>"x"\'') === '&lt;script&gt;&quot;x&quot;&apos;');
$check('safeUrl blocks javascript:', Escape::safeUrl('javascript:alert(1)') === '#');
$check('safeUrl blocks obfuscated scheme', Escape::safeUrl("java\tscript:alert(1)") === '#');
$check('safeUrl blocks data:', Escape::safeUrl('data:text/html,x') === '#');
$check('safeUrl allows https + relative', Escape::safeUrl('https://a.test/?a=1&b=2') === 'https://a.test/?a=1&amp;b=2' && Escape::safeUrl('/x') === '/x');
$check('json is script-safe', !str_contains(Escape::json(['a' => '</script><b>']), '<'));

echo "Views, layouts, error pages\n";
$views = __DIR__ . '/fixtures/views';
$app = new App();
$app->views($views)->share('shop', 'Life Shop');
$app->get('/', fn (Request $q, Response $r) => $r->render('home', ['name' => '<b>Cláudio</b>']));
$app->get('/bad', fn (Request $q, Response $r) => $r->render('../secret'));
$app->get('/boom', function (): void {
    throw new RuntimeException('internal secret');
});
$app->errorPage(404, 'errors/404')->errorPage(500, 'errors/500');

$r = $app->handle(new Request('GET', '/'));
$body = $r->getBody();
$check('render + layout + partial + shared var', str_contains($body, 'data-layout="main"') && str_contains($body, '<p class="part">2/Life Shop</p>'));
$check('render escapes user data (XSS)', str_contains($body, '&lt;b&gt;Cláudio&lt;/b&gt;') && !str_contains($body, '<b>Cláudio'));
$check('render sets text/html', str_starts_with((string) $r->getHeader('Content-Type'), 'text/html'));
$r = $app->handle(new Request('GET', '/bad'));
$check('path traversal in render rejected (500)', $r->getStatus() === 500);
$r = $app->handle(new Request('GET', '/missing'));
$check('custom 404 page', $r->getStatus() === 404 && str_contains($r->getBody(), 'Custom 404'));
$r = $app->handle(new Request('GET', '/boom'));
$check('custom 500 page, no leak', $r->getStatus() === 500 && str_contains($r->getBody(), 'Oops 500') && str_contains($r->getBody(), 'safe') && !str_contains($r->getBody(), 'internal secret'));
$r = $app->handle(new Request('GET', '/missing', headers: ['Accept' => 'application/json']));
$check('JSON clients still get JSON on 404', json_decode($r->getBody(), true)['error']['status'] === 404);
$check('render without views() throws LogicException', $throws(fn () => (new Response())->render('x'), LogicException::class));

echo "CSRF\n";
$secret = Csrf::generateSecret();
$app = new App();
$app->use(new Csrf($secret, except: ['/webhooks']));
$app->use(new ContentSecurityPolicy());
$app->get('/form', fn (Request $q, Response $r) => ['token' => $q->get('csrf_token')]);
$app->post('/pay', fn () => ['paid' => true]);
$app->post('/webhooks/x', fn () => ['hook' => true]);

$r = $app->handle(new Request('GET', '/form'));
$token = json_decode($r->getBody(), true)['token'];
preg_match('/tigger_csrf=([^;]+)/', $r->getCookies()[0] ?? '', $m);
$cookie = $m[1] ?? '';
$check('GET issues cookie (HttpOnly, SameSite=Lax) and token', $cookie !== '' && $token !== null
    && str_contains($r->getCookies()[0], 'HttpOnly') && str_contains($r->getCookies()[0], 'SameSite=Lax'));
$check('CSP header with nonce', str_contains((string) $r->getHeader('Content-Security-Policy'), "'nonce-") && isset($r->locals()['cspNonce']));

$post = static fn (array $h = [], array $b = [], string $c = 'tigger_csrf') => $app->handle(
    new Request('POST', '/pay', body: $b, headers: $h, cookies: $cookie !== '' ? [$c => $cookie] : [], secure: false)
);
$check('POST without token -> 403', $post()->getStatus() === 403);
$check('POST wrong token -> 403', $post(['X-CSRF-Token' => str_repeat('a', 64)])->getStatus() === 403);
$check('POST token but no cookie -> 403', $app->handle(new Request('POST', '/pay', headers: ['X-CSRF-Token' => $token]))->getStatus() === 403);
$check('POST valid token via header -> 200', $post(['X-CSRF-Token' => $token])->getStatus() === 200);
$check('POST valid token via _csrf field -> 200', $post([], ['_csrf' => $token])->getStatus() === 200);
$check('cross-origin Origin -> 403', $post(['X-CSRF-Token' => $token, 'Origin' => 'https://evil.test', 'Host' => 'shop.test'])->getStatus() === 403);
$check('same Origin -> 200', $post(['X-CSRF-Token' => $token, 'Origin' => 'https://shop.test', 'Host' => 'shop.test'])->getStatus() === 200);
$check('Sec-Fetch-Site cross-site -> 403', $post(['X-CSRF-Token' => $token, 'Sec-Fetch-Site' => 'cross-site'])->getStatus() === 403);
$check('null Origin -> 403', $post(['X-CSRF-Token' => $token, 'Origin' => 'null'])->getStatus() === 403);
$check('excepted path skips CSRF', $app->handle(new Request('POST', '/webhooks/x'))->getStatus() === 200);
$check('short secret rejected', $throws(fn () => new Csrf('short'), InvalidArgumentException::class));
$otherApp = new App();
$otherApp->use(new Csrf(Csrf::generateSecret()));
$otherApp->post('/pay', fn () => ['paid' => true]);
$check('token from another secret rejected', $otherApp->handle(new Request('POST', '/pay', headers: ['X-CSRF-Token' => $token], cookies: ['tigger_csrf' => $cookie]))->getStatus() === 403);

echo "Inbound webhooks\n";
$whsec = 'whsec_test_secret';
$body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'amount_total' => 5000]]]);
$stripeReq = static function (string $payload, ?int $ts = null, string $secret = 'whsec_test_secret') {
    $ts ??= time();
    $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);

    return new Request('POST', '/webhooks/stripe', headers: ['Stripe-Signature' => "t={$ts},v1={$sig}"], rawBody: $payload);
};

$dir = sys_get_temp_dir() . '/tigger_events_' . bin2hex(random_bytes(4));
$store = new FileEventStore($dir);
$calls = 0;
$seen = null;
$fail500 = false;
$hook = new App();
$hook->post('/webhooks/stripe', Webhook::receive(
    Webhook::stripe($whsec),
    function (WebhookEvent $e) use (&$calls, &$seen, &$fail500): void {
        if ($fail500) {
            throw new RuntimeException('db down');
        }
        $calls++;
        $seen = $e;
    },
    $store,
));

$r = $hook->handle($stripeReq($body));
$check('Stripe: valid signature accepted', $r->getStatus() === 200 && $calls === 1);
$check('Stripe: event parsed (type, id, data.object)', $seen?->type === 'checkout.session.completed' && $seen->id === 'evt_1' && $seen->data['amount_total'] === 5000);
$r = $hook->handle($stripeReq($body));
$check('Stripe: duplicate delivery ignored (idempotent)', $r->getStatus() === 200 && $calls === 1 && str_contains($r->getBody(), 'duplicate'));
$check('Stripe: tampered body -> 400', $hook->handle(new Request('POST', '/webhooks/stripe', headers: $stripeReq($body)->headers(), rawBody: $body . ' '))->getStatus() === 400);
$check('Stripe: wrong secret -> 400', $hook->handle($stripeReq($body, null, 'whsec_other'))->getStatus() === 400);
$check('Stripe: stale timestamp (replay) -> 400', $hook->handle($stripeReq($body, time() - 3600))->getStatus() === 400);
$check('Stripe: missing header -> 400', $hook->handle(new Request('POST', '/webhooks/stripe', rawBody: $body))->getStatus() === 400);
$body2 = json_encode(['id' => 'evt_2', 'type' => 'invoice.paid', 'data' => ['object' => []]]);
$fail500 = true;
$r = $hook->handle($stripeReq($body2));
$check('handler failure -> 500 and claim released', $r->getStatus() === 500);
$fail500 = false;
$r = $hook->handle($stripeReq($body2));
$check('provider retry succeeds after failure', $r->getStatus() === 200 && $calls === 2);

$rot = new App();
$rot->post('/w', Webhook::receive(Webhook::stripe(['whsec_new', 'whsec_old']), fn () => null));
$ts = time();
$check('Stripe: secret rotation (old secret still valid)', $rot->handle(new Request('POST', '/w', headers: ['Stripe-Signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", 'whsec_old')], rawBody: $body))->getStatus() === 200);

$bmc = new App();
$bmcType = null;
$bmc->post('/webhooks/bmc', Webhook::receive(Webhook::buyMeACoffee('bmc_secret'), function (WebhookEvent $e) use (&$bmcType): void {
    $bmcType = $e->type;
}));
$bmcBody = json_encode(['type' => 'donation.created', 'response' => ['supporter_name' => 'Ana']]);
$goodSig = hash_hmac('sha256', $bmcBody, 'bmc_secret');
$check('BMC: valid HMAC accepted', $bmc->handle(new Request('POST', '/webhooks/bmc', headers: ['X-Bmc-Signature' => $goodSig], rawBody: $bmcBody))->getStatus() === 200 && $bmcType === 'donation.created');
$check('BMC: uppercase hex also accepted', $bmc->handle(new Request('POST', '/webhooks/bmc', headers: ['X-Bmc-Signature' => strtoupper($goodSig)], rawBody: $bmcBody))->getStatus() === 200);
$check('BMC: bad HMAC -> 400', $bmc->handle(new Request('POST', '/webhooks/bmc', headers: ['X-Bmc-Signature' => str_repeat('0', 64)], rawBody: $bmcBody))->getStatus() === 400);

$gh = new App();
$gh->post('/w', Webhook::receive(Webhook::hmac('s3', 'X-Hub-Signature-256', prefix: 'sha256='), fn () => null));
$check('generic HMAC with prefix', $gh->handle(new Request('POST', '/w', headers: ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', '{"a":1}', 's3')], rawBody: '{"a":1}'))->getStatus() === 200);
$check('invalid JSON after valid signature -> 400', $bmc->handle(new Request('POST', '/webhooks/bmc', headers: ['X-Bmc-Signature' => hash_hmac('sha256', 'not json', 'bmc_secret')], rawBody: 'not json'))->getStatus() === 400);
$check('md5 algorithm refused', $throws(fn () => Webhook::hmac('s', 'X', 'md5'), InvalidArgumentException::class));
foreach (glob($dir . '/{*,.htaccess}', GLOB_BRACE) ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo "Outbound webhooks\n";
$d = new Dispatcher(secret: 'out_secret');
$blocked = static fn (string $url) => !$d->send($url, 'order.paid', ['id' => 1])->ok;
$check('SSRF: loopback blocked', $blocked('https://127.0.0.1/hook'));
$check('SSRF: localhost blocked', $blocked('https://localhost/hook'));
$check('SSRF: cloud metadata (169.254.169.254) blocked', $blocked('https://169.254.169.254/latest/meta-data'));
$check('SSRF: private 10.x / 192.168.x blocked', $blocked('https://10.0.0.5/x') && $blocked('https://192.168.1.10/x'));
$check('SSRF: CGNAT 100.64/10 blocked', $blocked('https://100.64.0.1/x'));
$check('SSRF: IPv6 loopback blocked', $blocked('https://[::1]/x'));
$check('SSRF: IPv4-mapped IPv6 blocked', $blocked('https://[::ffff:127.0.0.1]/x'));
$check('plain http refused by default', $blocked('http://example.com/x'));
$check('credentials in URL refused', $blocked('https://user:pw@example.com/x'));
$check('subscribe() rejects bad URL immediately', $throws(fn () => $d->subscribe('*', 'ftp://x.test/y'), InvalidArgumentException::class));
$res = $d->send('https://127.0.0.1/secret-path?token=abc', 'e');
$check('result never exposes path/query (secrets)', $res->host === '127.0.0.1' && !str_contains(json_encode($res), 'secret-path'));

// Live delivery against a local receiver (dev flags explicitly enabled)
$port = 18099;
@unlink(sys_get_temp_dir() . '/tigger_recv.json');
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/receiver.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock) { fclose($sock); break; }
    usleep(100_000);
}
$live = new Dispatcher(secret: 'out_secret', allowInsecureHttp: true, allowPrivateNetworks: true);
$live->subscribe('order.*', "http://127.0.0.1:{$port}/hook");
$live->subscribe('user.created', "http://127.0.0.1:{$port}/other");
$results = $live->emit('order.paid', ['order' => 42, 'total' => 5000]);
$check('emit() only hits matching subscriptions', count($results) === 1 && $results[0]->ok && $results[0]->status === 200);
$got = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/tigger_recv.json'), true);
$h = $got['headers'] ?? [];
$payload = json_decode($got['body'] ?? '', true);
$check('receiver got envelope (id, type, data)', ($payload['type'] ?? null) === 'order.paid' && ($payload['data']['total'] ?? null) === 5000 && str_starts_with($payload['id'] ?? '', 'evt_'));
$check('X-Tigger-Event / Event-Id headers', ($h['HTTP_X_TIGGER_EVENT'] ?? '') === 'order.paid' && ($h['HTTP_X_TIGGER_EVENT_ID'] ?? '') === ($payload['id'] ?? 'x'));
preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $h['HTTP_X_TIGGER_SIGNATURE'] ?? '', $sm);
$check('signature verifiable by receiver (t.body HMAC)', isset($sm[2]) && hash_equals(hash_hmac('sha256', $sm[1] . '.' . ($got['body'] ?? ''), 'out_secret'), $sm[2]));
$raw = $live->sendRaw("http://127.0.0.1:{$port}/slack", ['text' => 'Nova venda']);
$gotRaw = json_decode((string) file_get_contents(sys_get_temp_dir() . '/tigger_recv.json'), true);
$check('sendRaw: custom body, no envelope', $raw->ok && json_decode($gotRaw['body'], true) === ['text' => 'Nova venda']);
proc_terminate($proc);
proc_close($proc);
$dead = $live->send("http://127.0.0.1:{$port}/x", 'e');
$check('unreachable receiver -> ok=false, no exception', !$dead->ok && $dead->status === null && $dead->error !== null);

echo "terminating()\n";
$order = [];
$t = new App();
$t->get('/', function () use (&$order): array { $order[] = 'handler'; return ['ok' => true]; });
$t->terminating(function () use (&$order): void { $order[] = 'after'; });
$t->terminating(function (): void { throw new RuntimeException('webhook down'); });
ob_start();
$t->run(new Request('GET', '/'));
ob_end_clean();
$check('terminating runs after the response; failures are contained', $order === ['handler', 'after']);

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} failure(s).\n";
exit($fail === 0 ? 0 : 1);
