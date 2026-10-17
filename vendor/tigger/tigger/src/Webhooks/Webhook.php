<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use Closure;
use JsonException;
use Throwable;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;
use Tigger\Http\Response;

/**
 * Helpers to receive webhooks (Stripe, Buy Me a Coffee, any HMAC provider) safely.
 *
 * ```php
 * $app->use(new Csrf($secret, except: ['/webhooks']));
 *
 * $app->post('/webhooks/stripe', Webhook::receive(
 *     Webhook::stripe($_ENV['STRIPE_WEBHOOK_SECRET']),
 *     function (WebhookEvent $event, Request $req, Response $res): void {
 *         if ($event->type === 'checkout.session.completed') {
 *             // mark the order as paid using $event->data
 *         }
 *     },
 *     new FileEventStore(__DIR__ . '/storage/webhooks'),
 * ));
 * ```
 */
final class Webhook
{
    private function __construct()
    {
    }

    /**
     * Verifier for Stripe webhooks.
     *
     * @param string|list<string> $secret    Endpoint secret(s) (`whsec_...`).
     * @param int                 $tolerance Replay window in seconds.
     *
     * @return Verifier
     */
    public static function stripe(string|array $secret, int $tolerance = 300): Verifier
    {
        return new StripeVerifier($secret, $tolerance);
    }

    /**
     * Verifier for Buy Me a Coffee webhooks (`X-Bmc-Signature`, HMAC-SHA256 hex of the raw body).
     *
     * @param string|list<string> $secret Secret from the BMC webhook dashboard.
     *
     * @return Verifier
     */
    public static function buyMeACoffee(string|array $secret): Verifier
    {
        return new HmacVerifier($secret, 'X-Bmc-Signature');
    }

    /**
     * Verifier for any provider that sends an HMAC of the raw body in a header.
     *
     * @param string|list<string> $secret   Signing secret(s).
     * @param string              $header   Signature header name.
     * @param string              $algo     `sha256`, `sha384` or `sha512`.
     * @param string              $prefix   Fixed prefix before the signature (e.g. `sha256=`).
     * @param string              $encoding `hex` or `base64`.
     *
     * @return Verifier
     */
    public static function hmac(
        string|array $secret,
        string $header,
        string $algo = 'sha256',
        string $prefix = '',
        string $encoding = 'hex',
    ): Verifier {
        return new HmacVerifier($secret, $header, $algo, $prefix, $encoding);
    }

    /**
     * Builds a route handler that verifies, decodes and de-duplicates a webhook.
     *
     * Order of work: signature check (raw body) → JSON decode → idempotency claim → your handler.
     * If the handler throws, the claim is released and a 500 is returned so the provider retries.
     * When the handler does not respond itself, `200 {"received":true}` is sent.
     *
     * @param Verifier        $verifier Signature verifier.
     * @param callable        $handler  `function (WebhookEvent $event, Request $req, Response $res): void`.
     * @param EventStore|null $store    Idempotency store; null disables de-duplication.
     *
     * @return Closure Route handler `function (Request $req, Response $res): void`.
     */
    public static function receive(Verifier $verifier, callable $handler, ?EventStore $store = null): Closure
    {
        return function (Request $req, Response $res) use ($verifier, $handler, $store): void {
            $verifier->verify($req);

            try {
                $payload = json_decode($req->rawBody(), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException(400, 'Invalid webhook payload.');
            }
            if (!is_array($payload)) {
                throw new HttpException(400, 'Invalid webhook payload.');
            }

            $event = WebhookEvent::fromRequest($req, $payload);
            $key = $event->id ?? 'raw:' . hash('sha256', $req->rawBody());

            if ($store !== null && !$store->claim($key)) {
                $res->json(['received' => true, 'duplicate' => true]);

                return;
            }

            try {
                $handler($event, $req, $res);
            } catch (Throwable $e) {
                $store?->release($key);

                throw $e;
            }

            if (!$res->isFinished()) {
                $res->json(['received' => true]);
            }
        };
    }
}
