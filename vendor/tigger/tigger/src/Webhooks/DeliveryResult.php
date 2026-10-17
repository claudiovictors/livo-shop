<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

/**
 * The outcome of one outbound webhook delivery.
 */
final class DeliveryResult
{
    /**
     * @param bool        $ok       True when the receiver answered with a 2xx status.
     * @param int|null    $status   HTTP status code, or null when no response was received.
     * @param string      $eventId  Id of the event (stable across retries; receivers can de-duplicate on it).
     * @param string      $host     Receiver host (the full URL is never exposed: it often contains a secret).
     * @param string|null $error    Failure reason, if any.
     * @param int         $attempts Number of attempts made.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly string $eventId,
        public readonly string $host,
        public readonly ?string $error = null,
        public readonly int $attempts = 1,
    ) {
    }
}
