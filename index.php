<?php

declare(strict_types= 1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\Cors;
use Tigger\Middleware\Csrf;
use Tigger\Middleware\SecureHeaders;

$app = new App(debug: false);

$secret = getenv('APP_SECRET') ?: 'change-me-with-Csrf::generateSecret()-output';

$app->use(new SecureHeaders());
$app->use(new Cors(['origins' => ['*']]));
$app->use(new Csrf($secret, except: ['/webhooks', '/api']));

$app->views(__DIR__ . '/public');
$app->errorPage(404, '404');

$app->get('/', function(Request $req, Response $res) {
    return $res->render('home');
});

$app->get('/contact', function(Request $req, Response $res) {
    return $res->render('contact');
});

$app->get('/about', function(Request $req, Response $res) {
    return $res->render('about');
});

$app->post('/checkout', function(Request $req, Response $res) {
    return $res->json(['message' => 'Olá!']);
});

$app->run();