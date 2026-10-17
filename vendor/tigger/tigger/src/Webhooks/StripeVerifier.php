<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use InvalidArgumentException;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;

/**
 * Verifies Stripe's `Stripe-Signature` header (`t=<unix>,v1=<hmac>`).
 *
 * The signature is HMAC-SHA256 over `"<t>.<raw body>"` using the endpoint secret
 * (`whsec_...`). Timestamps outside the tolerance window are rejected to stop replays.
 * Pass several secrets while rotating a secret.
 */
final class StripeVerifier implements Verifier
{
    /** @var list<string> */
    private array $secrets;

    /**
     * @param string|list<string> $secrets   Endpoint signing secret(s).
     * @param int                 $tolerance Maximum age/skew in seconds (0 disables the check — not recommended).
     *
     * @throws InvalidArgumentException If no usable secret is given.
     */
    public function __construct(string|array $secrets, private readonly int $tolerance = 300)
    {
        $this->secrets = array_values(array_filter((array) $secrets, static fn ($s): bool => is_string($s) && $s !== ''));

        if ($this->secrets === []) {
            throw new InvalidArgumentException('A webhook signing secret is required.');
        }
    }

    /**
     * @param Request $request Incoming webhook request.
     *
     * @return void
     *
     * @throws HttpException 400 on any verification failure.
     */
    public function verify(Request $request): void
    {
        $header = $request->header('stripe-signature');
        if ($header === null) {
            throw self::fail();
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            throw self::fail();
        }

        if ($this->tolerance > 0 && abs(time() - (int) $timestamp) > $this->tolerance) {
            throw self::fail();
        }

        $signed = $timestamp . '.' . $request->rawBody();

        foreach ($this->secrets as $secret) {
            $expected = hash_hmac('sha256', $signed, $secret);
            foreach ($signatures as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }

        throw self::fail();
    }

    /**
     * @return HttpException A generic 400 (never reveals why verification failed).
     */
    private static function fail(): HttpException
    {
        return new HttpException(400, 'Invalid webhook signature.');
    }
}
