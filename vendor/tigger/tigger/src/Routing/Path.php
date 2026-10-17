<?php

declare(strict_types=1);

namespace Tigger\Routing;

/**
 * Internal helpers for working with URL paths.
 *
 * @internal
 */
final class Path
{
    private function __construct()
    {
    }

    /**
     * Normalizes a path: single leading slash, no trailing slash, no duplicate slashes.
     *
     * @param string $path Raw path.
     *
     * @return string Normalized path ("/" for the root).
     */
    public static function normalize(string $path): string
    {
        return preg_replace('#/+#', '/', '/' . trim($path, '/')) ?? '/';
    }

    /**
     * Joins a prefix and a path into one normalized path.
     *
     * @param string $prefix Path prefix.
     * @param string $path   Path to append.
     *
     * @return string
     */
    public static function join(string $prefix, string $path): string
    {
        return self::normalize($prefix . '/' . $path);
    }

    /**
     * Tells whether a request path falls under a prefix (segment-aware).
     *
     * @param string $prefix Prefix such as "/api".
     * @param string $path   Normalized request path.
     *
     * @return bool
     */
    public static function matchesPrefix(string $prefix, string $path): bool
    {
        $prefix = self::normalize($prefix);

        return $prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
