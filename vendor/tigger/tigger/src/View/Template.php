<?php

declare(strict_types=1);

namespace Tigger\View;

use Tigger\Security\Escape;

/**
 * The `$this` available inside view files.
 *
 * ```php
 * <?php $this->layout('layouts/main') ?>
 * <h1><?= $this->e($title) ?></h1>
 * <?php $this->partial('partials/footer') ?>
 * ```
 */
final class Template
{
    private ?string $layout = null;

    /**
     * @param View                $view Engine used for partials.
     * @param array<string,mixed> $data Variables available to the template.
     */
    public function __construct(private readonly View $view, private readonly array $data)
    {
    }

    /**
     * Escapes a value for HTML text or a quoted attribute.
     *
     * @param mixed $value Value to escape.
     *
     * @return string
     */
    public function e(mixed $value): string
    {
        return Escape::html($value);
    }

    /**
     * Percent-encodes a value for a URL.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    public function url(string $value): string
    {
        return Escape::url($value);
    }

    /**
     * Validates a URL for `href`/`src` (blocks `javascript:` etc.) and returns it escaped.
     *
     * @param string $url URL to check.
     *
     * @return string
     */
    public function safeUrl(string $url): string
    {
        return Escape::safeUrl($url);
    }

    /**
     * Encodes data as JSON safe for embedding inside `<script>`.
     *
     * @param mixed $value Value to encode.
     *
     * @return string
     */
    public function json(mixed $value): string
    {
        return Escape::json($value);
    }

    /**
     * Selects the layout that wraps this template (its output is exposed as `$content`).
     *
     * @param string $name Layout template name.
     *
     * @return void
     */
    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    /**
     * @return string|null The layout chosen with {@see Template::layout()}.
     */
    public function getLayout(): ?string
    {
        return $this->layout;
    }

    /**
     * Includes another template (no layout) at this point.
     *
     * @param string              $template Template name.
     * @param array<string,mixed> $data     Extra variables, merged over the current ones.
     *
     * @return void
     */
    public function partial(string $template, array $data = []): void
    {
        echo $this->view->renderPartial($template, array_replace($this->data, $data));
    }

    /**
     * Outputs a hidden CSRF input. Requires the {@see \Tigger\Middleware\Csrf} middleware.
     *
     * @return string Trusted HTML (already escaped); echo it as-is.
     */
    public function csrfField(): string
    {
        $token = $this->data['csrfToken'] ?? null;

        return is_string($token)
            ? '<input type="hidden" name="_csrf" value="' . Escape::html($token) . '">'
            : '';
    }

    /**
     * @return string The CSRF token for AJAX calls (send it as the `X-CSRF-Token` header).
     */
    public function csrfToken(): string
    {
        $token = $this->data['csrfToken'] ?? '';

        return is_string($token) ? $token : '';
    }

    /**
     * @return string The CSP nonce for inline `<script nonce="...">` / `<style nonce="...">`.
     */
    public function nonce(): string
    {
        $nonce = $this->data['cspNonce'] ?? '';

        return is_string($nonce) ? Escape::html($nonce) : '';
    }
}
