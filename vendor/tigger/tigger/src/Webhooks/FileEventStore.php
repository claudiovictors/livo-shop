<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use RuntimeException;

/**
 * File-based {@see EventStore}: one empty file per event, created atomically (`fopen` mode "x").
 *
 * Works on any shared host. Point it at a directory OUTSIDE the web root when you can;
 * a deny-all `.htaccess` is written as a safety net.
 */
final class FileEventStore implements EventStore
{
    /**
     * @param string $directory Storage directory (created if missing).
     *
     * @throws RuntimeException If the directory cannot be created.
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory \"{$directory}\".");
        }

        $guard = rtrim($directory, '/\\') . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }
    }

    /**
     * @param string $key Unique event key.
     *
     * @return bool
     *
     * @throws RuntimeException If the directory is not writable.
     */
    public function claim(string $key): bool
    {
        $file = $this->path($key);
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            if (is_file($file)) {
                return false;
            }
            throw new RuntimeException("Cannot write to \"{$this->directory}\".");
        }

        fclose($handle);

        return true;
    }

    /**
     * @param string $key Unique event key.
     *
     * @return void
     */
    public function release(string $key): void
    {
        @unlink($this->path($key));
    }

    /**
     * Deletes claims older than the given age (call it from a cron job or occasionally).
     *
     * @param int $olderThanSeconds Minimum age in seconds.
     *
     * @return int Number of claims removed.
     */
    public function prune(int $olderThanSeconds): int
    {
        $removed = 0;
        foreach (glob(rtrim($this->directory, '/\\') . '/*.evt') ?: [] as $file) {
            if (filemtime($file) < time() - $olderThanSeconds && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param string $key Unique event key.
     *
     * @return string Safe file path for the key.
     */
    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . hash('sha256', $key) . '.evt';
    }
}
