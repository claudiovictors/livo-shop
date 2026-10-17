<?php

declare(strict_types=1);

// Minimal dependency-free smoke test: php tests/smoke.php
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Tigger\\'))) . '.php';
    if (str_starts_with($class, 'Tigger\\') && is_file($file)) {
        require $file;
    }
});

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\Cors;
use Tigger\Middleware\SecureHeaders;
use Tigger\Routing\Router;

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . PHP_EOL;
    $fail += $ok ? 0 : 1;
};

$app = new App();
$order = [];

$app->use(new SecureHeaders());
$app->use(new Cors(['origins' => ['https://site.test']]));
$app->use(function (Request $req, Response $res, callable $next) use (&$order): void {
    $order[] = 'global';
    $next();
    $order[] = 'global-after';
});

$app->get('/', function (Request $req, Response $res): void {
    $res->json(['message' => 'Hello, World!']);
})->name('home');

$app->get('/users/:id', fn (Request $req, Response $res) => $res->json(['id' => $req->param('id')]))
    ->where('id', '[0-9]+')->name('users.show');

$app->get('/mw', function (Request $req, Response $res): array {
    return ['user' => $req->get('user')];
})->middleware(function (Request $req, Response $res, callable $next) use (&$order): void {
    $order[] = 'route';
    $req->set('user', 'claudio');
    $next();
});

$app->get('/boom', function (): void {
    throw new RuntimeException('secret internals');
});
$app->get('/files/*', fn (Request $req, Response $res) => ['file' => $req->param('wildcard')]);

$auth = function (Request $req, Response $res, callable $next): void {
    if ($req->bearerToken() === null) {
        $res->status(401)->json(['error' => 'Unauthorized']);

        return;
    }
    $next();
};
$api = new Router();
$api->use('/admin', $auth);
$api->get('/ping', fn () => ['pong' => true]);
$api->get('/admin/stats', fn () => ['ok' => true]);
$app->use('/api', $api);

$json = static fn (Response $r): array => json_decode($r->getBody(), true);

$r = $app->handle(new Request('GET', '/'));
$check('GET / -> 200 json', $r->getStatus() === 200 && $json($r) === ['message' => 'Hello, World!']);
$check('security headers present', $r->getHeader('X-Content-Type-Options') === 'nosniff');
$check('global middleware before/after order', $order === ['global', 'global-after']);

$r = $app->handle(new Request('GET', '/users/42'));
$check('param :id', $json($r) === ['id' => '42']);
$r = $app->handle(new Request('GET', '/users/abc'));
$check('where() constraint -> 404', $r->getStatus() === 404);
$r = $app->handle(new Request('POST', '/'));
$check('405 + Allow header', $r->getStatus() === 405 && str_contains((string) $r->getHeader('Allow'), 'GET'));
$r = $app->handle(new Request('GET', '/nope', headers: ['Accept' => 'application/json']));
$check('404 json error body', $r->getStatus() === 404 && $json($r)['error']['status'] === 404);
$r = $app->handle(new Request('GET', '/nope'));
$check('404 html error body', str_contains($r->getBody(), '<h1>404 Not Found</h1>'));

$order = [];
$r = $app->handle(new Request('GET', '/mw'));
$check('route middleware + req->set/get', $json($r) === ['user' => 'claudio'] && $order === ['global', 'route', 'global-after']);

$r = $app->handle(new Request('GET', '/boom', headers: ['Accept' => 'application/json']));
$check('500 hides internals when debug=false', $r->getStatus() === 500 && !str_contains($r->getBody(), 'secret'));

$r = $app->handle(new Request('GET', '/files/a/b.txt'));
$check('wildcard', $json($r) === ['file' => 'a/b.txt']);

$r = $app->handle(new Request('GET', '/api/ping'));
$check('mounted router /api/ping', $json($r) === ['pong' => true]);
$r = $app->handle(new Request('GET', '/api/admin/stats'));
$check('router-level middleware blocks (401)', $r->getStatus() === 401);
$r = $app->handle(new Request('GET', '/api/admin/stats', headers: ['Authorization' => 'Bearer abc']));
$check('router-level middleware passes with token', $r->getStatus() === 200);

$r = $app->handle(new Request('OPTIONS', '/', headers: [
    'Origin' => 'https://site.test', 'Access-Control-Request-Method' => 'POST',
]));
$check('CORS preflight -> 204', $r->getStatus() === 204 && $r->getHeader('Access-Control-Allow-Origin') === 'https://site.test');
$r = $app->handle(new Request('GET', '/', headers: ['Origin' => 'https://evil.test']));
$check('CORS blocks unknown origin', $r->getHeader('Access-Control-Allow-Origin') === null);

$r = $app->handle(new Request('HEAD', '/'));
$check('HEAD falls back to GET', $r->getStatus() === 200);

$check('url() named route', $app->url('users.show', ['id' => 7], ['tab' => 'a b']) === '/users/7?tab=a+b');

try {
    (new Response())->header("X-Test", "a\r\nSet-Cookie: x=1");
    $check('header injection rejected', false);
} catch (InvalidArgumentException) {
    $check('header injection rejected', true);
}

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} failure(s).\n";
exit($fail === 0 ? 0 : 1);
