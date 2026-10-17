<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\ContentSecurityPolicy;
use Tigger\Middleware\Csrf;
use Tigger\Middleware\SecureHeaders;
use Tigger\Webhooks\Dispatcher;
use Tigger\Webhooks\FileEventStore;
use Tigger\Webhooks\Webhook;
use Tigger\Webhooks\WebhookEvent;

// Keep secrets out of the repository (environment variables or a file outside the web root).
$csrfSecret = getenv('APP_SECRET') ?: 'change-me-with-Csrf::generateSecret()-output';

$app = new App(debug: false);

// Views + custom error pages
$app->views(__DIR__ . '/views', layout: 'layouts/main')->share('shop', 'Life Shop');
$app->errorPage(404, 'errors/404')->errorPage(500, 'errors/500');

// Global security middleware
$app->use(new SecureHeaders());
$app->use(new ContentSecurityPolicy());
$app->use(new Csrf($csrfSecret, except: ['/webhooks']));

// Pages
$app->get('/', fn (Request $req, Response $res) => $res->render('home', ['name' => $req->query('name', 'friend')]));

$app->post('/checkout', function (Request $req, Response $res): void {
    // CSRF already validated by the middleware
    $res->redirect('/');
});

// Outgoing events (Zapier, n8n, Slack...)
$hooks = new Dispatcher(secret: getenv('OUTBOUND_WEBHOOK_SECRET') ?: null);
$hooks->subscribe('order.*', 'https://hooks.example.com/your-endpoint');

// Incoming payment webhooks: signature verified, duplicates ignored
$app->post('/webhooks/stripe', Webhook::receive(
    Webhook::stripe(getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_...'),
    function (WebhookEvent $event, Request $req, Response $res) use ($app, $hooks): void {
        if ($event->type === 'checkout.session.completed') {
            // mark the order as paid using $event->data ...
            $app->terminating(fn () => $hooks->emit('order.paid', ['session' => $event->data['id'] ?? null]));
        }
    },
    new FileEventStore(__DIR__ . '/storage/webhooks'),
));

$app->post('/webhooks/buymeacoffee', Webhook::receive(
    Webhook::buyMeACoffee(getenv('BMC_WEBHOOK_SECRET') ?: 'secret'),
    function (WebhookEvent $event): void {
        // $event->type, $event->data
    },
    new FileEventStore(__DIR__ . '/storage/webhooks'),
));

$app->run();
