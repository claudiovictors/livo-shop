<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use Tigger\Http\Request;

/**
 * A verified, decoded incoming webhook.
 */
final class WebhookEvent
{
    /**
     * @param string|null         $id      Provider event id, when present.
     * @param string              $type    Event type, e.g. "checkout.session.completed" or "donation.created".
     * @param array<string,mixed> $data    Best-effort event data (`data.object`, then `data`, then the payload).
     * @param array<string,mixed> $payload The full decoded JSON body.
     * @param string              $raw     The raw body, as received.
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly array $payload,
        public readonly string $raw,
    ) {
    }

    /**
     * Builds an event from a verified request and its decoded payload.
     *
     * @param Request             $request Incoming request.
     * @param array<string,mixed> $payload Decoded JSON body.
     *
     * @return self
     */
    public static function fromRequest(Request $request, array $payload): self
    {
        $type = $payload['type'] ?? $payload['event'] ?? $payload['event_type']
            ?? $request->header('x-bmc-event') ?? $request->header('x-event-type') ?? 'unknown';

        $id = $payload['id'] ?? $payload['event_id'] ?? $request->header('x-webhook-id');

        $data = $payload['data']['object'] ?? $payload['data'] ?? $payload;

        return new self(
            is_scalar($id) ? (string) $id : null,
            is_scalar($type) ? (string) $type : 'unknown',
            is_array($data) ? $data : $payload,
            $payload,
            $request->rawBody(),
        );
    }
}
