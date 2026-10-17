<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Sends real-time events from your app to any tool that accepts webhooks
 * (Zapier, n8n, Make, Slack, Discord, your own services...).
 *
 * Every delivery is a JSON POST:
 *
 * ```json
 * {"id": "evt_...", "type": "order.paid", "created": 1760000000, "data": {...}}
 * ```
 *
 * with headers `X-Tigger-Event`, `X-Tigger-Event-Id`, `X-Tigger-Timestamp` and, when a
 * secret is set, `X-Tigger-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">` — the same
 * scheme Stripe uses, so receivers can verify it with {@see StripeVerifier}-style code.
 *
 * Built-in SSRF protection: only HTTPS by default, no credentials in URLs, every resolved
 * IP must be public, the connection is pinned to the checked IP (no DNS rebinding) and
 * redirects are never followed. Requires the cURL extension.
 *
 * Deliveries are synchronous: queue them with `$app->terminating()` so users never wait.
 */
final class Dispatcher
{
    private const MAX_RESPONSE_BYTES = 65536;

    /** @var list<array{0: string, 1: string, 2: ?string}> [pattern, url, secret] */
    private array $subscriptions = [];

    /**
     * @param string|null $secret               Default signing secret for signed deliveries.
     * @param int         $timeout              Seconds before a delivery is abandoned.
     * @param int         $retries              Extra attempts after a network error or 5xx (0–3).
     * @param bool        $allowInsecureHttp    Allow `http://` receivers (development only).
     * @param bool        $allowPrivateNetworks Allow private/loopback receivers (development only; disables SSRF protection).
     */
    public function __construct(
        private readonly ?string $secret = null,
        private readonly int $timeout = 5,
        private readonly int $retries = 0,
        private readonly bool $allowInsecureHttp = false,
        private readonly bool $allowPrivateNetworks = false,
    ) {
        if ($timeout < 1 || $retries < 0 || $retries > 3) {
            throw new InvalidArgumentException('Timeout must be >= 1 and retries between 0 and 3.');
        }
    }

    /**
     * Registers a receiver for an event pattern.
     *
     * @param string      $event  Event name, `*` for everything, or a prefix such as `order.*`.
     * @param string      $url    Receiver URL.
     * @param string|null $secret Per-receiver secret (defaults to the dispatcher's).
     *
     * @return static
     *
     * @throws InvalidArgumentException If the URL is invalid or not allowed.
     */
    public function subscribe(string $event, string $url, ?string $secret = null): static
    {
        $this->assertUrlSyntax($url);
        $this->subscriptions[] = [$event, $url, $secret];

        return $this;
    }

    /**
     * Sends an event to every matching subscription.
     *
     * @param string              $event   Event name, e.g. "order.paid".
     * @param array<string,mixed> $payload Event data.
     *
     * @return list<DeliveryResult> One result per receiver (never throws on delivery failure).
     */
    public function emit(string $event, array $payload = []): array
    {
        $results = [];

        foreach ($this->subscriptions as [$pattern, $url, $secret]) {
            if (self::matches($pattern, $event)) {
                $results[] = $this->send($url, $event, $payload, $secret);
            }
        }

        return $results;
    }

    /**
     * Sends one event envelope to one URL.
     *
     * @param string              $url     Receiver URL.
     * @param string              $event   Event name.
     * @param array<string,mixed> $payload Event data.
     * @param string|null         $secret  Signing secret (defaults to the dispatcher's).
     *
     * @return DeliveryResult
     *
     * @throws RuntimeException If the cURL extension is missing.
     */
    public function send(string $url, string $event, array $payload = [], ?string $secret = null): DeliveryResult
    {
        $eventId = 'evt_' . bin2hex(random_bytes(12));

        try {
            $body = json_encode(
                ['id' => $eventId, 'type' => $event, 'created' => time(), 'data' => $payload],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            return new DeliveryResult(false, null, $eventId, self::hostOf($url), 'Payload is not JSON-encodable.', 0);
        }

        return $this->deliver($url, $body, $secret ?? $this->secret, $eventId, [
            'X-Tigger-Event' => $event,
            'X-Tigger-Event-Id' => $eventId,
        ]);
    }

    /**
     * Sends a custom JSON body without the Tigger envelope — for tools with their own format,
     * e.g. Slack `['text' => '...']` or Discord `['content' => '...']`.
     *
     * @param string              $url    Receiver URL.
     * @param array<string,mixed> $json   JSON body.
     * @param string|null         $secret Optional signing secret.
     *
     * @return DeliveryResult
     *
     * @throws RuntimeException If the cURL extension is missing.
     */
    public function sendRaw(string $url, array $json, ?string $secret = null): DeliveryResult
    {
        $eventId = 'evt_' . bin2hex(random_bytes(12));

        try {
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return new DeliveryResult(false, null, $eventId, self::hostOf($url), 'Payload is not JSON-encodable.', 0);
        }

        return $this->deliver($url, $body, $secret, $eventId, []);
    }

    /**
     * Validates, signs and posts a body, retrying transient failures.
     *
     * @param string               $url     Receiver URL.
     * @param string               $body    JSON body.
     * @param string|null          $secret  Signing secret.
     * @param string               $eventId Event id.
     * @param array<string,string> $headers Extra headers.
     *
     * @return DeliveryResult
     *
     * @throws RuntimeException If the cURL extension is missing.
     */
    private function deliver(string $url, string $body, ?string $secret, string $eventId, array $headers): DeliveryResult
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required to send webhooks.');
        }

        $host = self::hostOf($url);

        try {
            $target = $this->resolveTarget($url);
        } catch (InvalidArgumentException $e) {
            return new DeliveryResult(false, null, $eventId, $host, $e->getMessage(), 0);
        }

        $attempt = 0;
        $status = null;
        $error = null;

        do {
            $attempt++;
            $timestamp = time();

            $lines = [
                'Content-Type: application/json',
                'User-Agent: Tigger-Webhooks/1.0',
                'X-Tigger-Timestamp: ' . $timestamp,
            ];
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            if ($secret !== null && $secret !== '') {
                $lines[] = 'X-Tigger-Signature: t=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            }

            [$status, $error] = $this->post($url, $body, $lines, $target);

            $ok = $status !== null && $status >= 200 && $status < 300;
            $retryable = $status === null || $status >= 500;

            if ($ok || !$retryable || $attempt > $this->retries) {
                break;
            }

            usleep(200_000 * (2 ** ($attempt - 1)));
        } while (true);

        $ok = $status !== null && $status >= 200 && $status < 300;

        return new DeliveryResult($ok, $status, $eventId, $host, $ok ? null : ($error ?? 'Receiver answered HTTP ' . $status), $attempt);
    }

    /**
     * Performs the HTTP request with cURL, pinned to the validated IP.
     *
     * @param string       $url    Receiver URL.
     * @param string       $body   JSON body.
     * @param list<string> $lines  Header lines.
     * @param array{host: string, port: int, ip: string} $target Validated connection target.
     *
     * @return array{0: ?int, 1: ?string} [status, error]
     */
    private function post(string $url, string $body, array $lines, array $target): array
    {
        $received = 0;
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ip],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                $received += strlen($chunk);

                return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ]);

        $done = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);

        if ($status > 0) {
            return [$status, null];
        }

        return [null, $done === false ? 'Delivery failed (cURL error ' . $errno . ').' : 'No response.'];
    }

    /**
     * Validates the URL and returns a pinned connection target.
     *
     * @param string $url Receiver URL.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws InvalidArgumentException If the URL is not allowed or resolves to a non-public address.
     */
    private function resolveTarget(string $url): array
    {
        $parts = $this->assertUrlSyntax($url);
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        $ips = self::resolveIps($host);
        if ($ips === []) {
            throw new InvalidArgumentException('Receiver host could not be resolved.');
        }

        if (!$this->allowPrivateNetworks) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new InvalidArgumentException('Receiver resolves to a non-public address.');
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Checks scheme, host and credentials of a URL.
     *
     * @param string $url Receiver URL.
     *
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string}
     *
     * @throws InvalidArgumentException If the URL is not acceptable.
     */
    private function assertUrlSyntax(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('Invalid webhook URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid webhook URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowInsecureHttp)) {
            throw new InvalidArgumentException('Webhook URLs must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in webhook URLs are not allowed.');
        }

        /** @var array{scheme: string, host: string, port?: int, path?: string, query?: string} $parts */
        return $parts;
    }

    /**
     * Resolves a host to its IP addresses.
     *
     * @param string $host Hostname or IP literal.
     *
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    /**
     * Tells whether an IP address is publicly routable.
     *
     * @param string $ip IPv4 or IPv6 address.
     *
     * @return bool
     */
    private static function isPublicIp(string $ip): bool
    {
        if (str_contains($ip, ':') && str_contains($ip, '.')) {
            $ip = substr($ip, (int) strrpos($ip, ':') + 1); // IPv4-mapped IPv6
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return $long !== false && ($long & 0xFFC00000) !== 0x64400000; // 100.64.0.0/10 (CGNAT)
        }

        return true;
    }

    /**
     * Tells whether an event name matches a subscription pattern.
     *
     * @param string $pattern `*`, an exact name, or a prefix ending in `.*`.
     * @param string $event   Event name.
     *
     * @return bool
     */
    private static function matches(string $pattern, string $event): bool
    {
        return $pattern === '*'
            || $pattern === $event
            || (str_ends_with($pattern, '.*') && str_starts_with($event, substr($pattern, 0, -1)));
    }

    /**
     * Extracts the host of a URL for display (never the path or query, which may hold secrets).
     *
     * @param string $url URL.
     *
     * @return string
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'unknown';
    }
}
