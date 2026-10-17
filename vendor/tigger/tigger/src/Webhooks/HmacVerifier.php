<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use InvalidArgumentException;
use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;

/**
 * Verifies a plain HMAC of the raw body carried in a header — the scheme used by
 * Buy Me a Coffee (`X-Bmc-Signature`), GitHub (`X-Hub-Signature-256`, prefix `sha256=`)
 * and many others.
 */
final class HmacVerifier implements Verifier
{
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /** @var list<string> */
    private array $secrets;

    /**
     * @param string|list<string> $secrets  Signing secret(s).
     * @param string              $header   Header carrying the signature.
     * @param string              $algo     `sha256`, `sha384` or `sha512`.
     * @param string              $prefix   Fixed prefix before the signature, e.g. `sha256=`.
     * @param string              $encoding `hex` or `base64`.
     *
     * @throws InvalidArgumentException On an empty secret/header or unsupported option.
     */
    public function __construct(
        string|array $secrets,
        private readonly string $header,
        private readonly string $algo = 'sha256',
        private readonly string $prefix = '',
        private readonly string $encoding = 'hex',
    ) {
        $this->secrets = array_values(array_filter((array) $secrets, static fn ($s): bool => is_string($s) && $s !== ''));

        if ($this->secrets === [] || $header === '') {
            throw new InvalidArgumentException('A signing secret and a signature header are required.');
        }
        if (!in_array($algo, self::ALGORITHMS, true)) {
            throw new InvalidArgumentException('Unsupported HMAC algorithm.');
        }
        if (!in_array($encoding, ['hex', 'base64'], true)) {
            throw new InvalidArgumentException('Encoding must be "hex" or "base64".');
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
        $value = $request->header($this->header);
        if ($value === null || ($this->prefix !== '' && !str_starts_with($value, $this->prefix))) {
            throw new HttpException(400, 'Invalid webhook signature.');
        }

        $candidate = substr(trim($value), strlen($this->prefix));
        if ($this->encoding === 'hex') {
            $candidate = strtolower($candidate);
        }

        foreach ($this->secrets as $secret) {
            $raw = hash_hmac($this->algo, $request->rawBody(), $secret, true);
            $expected = $this->encoding === 'hex' ? bin2hex($raw) : base64_encode($raw);

            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new HttpException(400, 'Invalid webhook signature.');
    }
}
