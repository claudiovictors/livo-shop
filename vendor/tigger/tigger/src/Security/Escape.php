<?php

declare(strict_types=1);

namespace Tigger\Security;

use InvalidArgumentException;
use Stringable;

/**
 * Context-aware output escaping — your main defence against XSS.
 *
 * Escape at the point of output, using the function that matches the context:
 * HTML text/attributes ({@see Escape::html()}), URLs ({@see Escape::url()},
 * {@see Escape::safeUrl()}) and data embedded in `<script>` ({@see Escape::json()}).
 * Always wrap HTML attribute values in quotes.
 */
final class Escape
{
    private function __construct()
    {
    }

    /**
     * Escapes a value for HTML text or a quoted HTML attribute.
     *
     * @param mixed $value Scalar, null or Stringable.
     *
     * @return string
     *
     * @throws InvalidArgumentException If the value is an array or a non-stringable object.
     */
    public static function html(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException('Only scalar or Stringable values can be escaped.');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Percent-encodes a value for use as a URL path segment or query value.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    public static function url(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Validates a URL for use in `href`/`src` and returns it HTML-escaped.
     *
     * Blocks `javascript:`, `data:`, `vbscript:` and any scheme outside the allow-list
     * (relative URLs are accepted). Returns "#" for unsafe URLs.
     *
     * @param string       $url     URL to check.
     * @param list<string> $schemes Allowed schemes.
     *
     * @return string
     */
    public static function safeUrl(string $url, array $schemes = ['http', 'https', 'mailto', 'tel']): string
    {
        // Browsers ignore control characters and whitespace inside the scheme ("java\tscript:").
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $m) === 1
            && !in_array(strtolower($m[1]), $schemes, true)) {
            return '#';
        }

        return self::html($url);
    }

    /**
     * Encodes data as JSON that is safe inside an HTML `<script>` block or a quoted attribute.
     *
     * @param mixed $value Any JSON-encodable value.
     *
     * @return string
     *
     * @throws \JsonException If the value cannot be encoded.
     */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
