<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

/**
 * Remembers which webhook events were already processed (idempotency).
 *
 * Providers retry deliveries, so a payment event can arrive twice; without this
 * you could ship an order twice.
 */
interface EventStore
{
    /**
     * Atomically claims an event key.
     *
     * @param string $key Unique event key.
     *
     * @return bool True if this call claimed it; false if it was already claimed.
     */
    public function claim(string $key): bool;

    /**
     * Releases a claim so the provider's retry can be processed again (call it when handling failed).
     *
     * @param string $key Unique event key.
     *
     * @return void
     */
    public function release(string $key): void;
}
