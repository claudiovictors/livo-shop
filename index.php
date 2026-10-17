<?php

declare(strict_types= 1);

require __DIR__ . '/vendor/autoload.php';

use Tigger\App;
use Tigger\Http\Request;
use Tigger\Http\Response;
use Tigger\Middleware\Cors;
use Tigger\Middleware\Csrf;
use Tigger\Webhooks\Webhook;
use Tigger\Webhooks\WebhookEvent;
use Tigger\Webhooks\FileEventStore;
use Tigger\Middleware\SecureHeaders;

$app = new App(debug: false);

$secret = getenv('APP_SECRET') ?: 'change-me-with-Csrf::generateSecret()-output';
$webhookSecret = getenv('BMC_WEBHOOK_SECRET');

if (!$webhookSecret) {
    throw new RuntimeException(
        'BMC_WEBHOOK_SECRET não está configurado.'
    );
}

$eventStorePath = sys_get_temp_dir() . '/livo-bmc-webhooks';

if (!is_dir($eventStorePath)) {
    mkdir($eventStorePath, 0700, true);
}

$app->use(new SecureHeaders());
$app->use(new Cors(['origins' => ['*']]));
$app->use(new Csrf(
    $secret,
    except: ['/webhooks', '/api']
));

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

$app->post('/webhooks/buymeacoffee', Webhook::receive(
    Webhook::buyMeACoffee($webhookSecret),
    function (WebhookEvent $event): void {
        error_log('[BMC] Evento recebido: ' . $event->type);

        if ($event->type === 'extra_purchase.created') {
            error_log(
                '[LIVO] Compra recebida para análise.'
            );
        }

        if ($event->type === 'extra_purchase.updated') {
            error_log(
                '[LIVO] Atualização de compra recebida.'
            );
        }

        if ($event->type === 'extra_purchase.refunded') {
            error_log(
                '[LIVO] Evento de reembolso recebido.'
            );
        }
    },
    new FileEventStore($eventStorePath),
));

$app->run();