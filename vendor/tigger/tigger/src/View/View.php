<?php

declare(strict_types=1);

namespace Tigger\View;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * A tiny PHP template engine with layouts, partials and safe path resolution.
 *
 * Template names are resolved inside the views directory only: `../`,
 * absolute paths and symlinks pointing outside are rejected.
 */
final class View
{
    private string $directory;

    /** @var array<string,mixed> */
    private array $shared = [];

    /**
     * @param string      $directory     Views directory (must exist).
     * @param string      $extension     File extension appended to template names.
     * @param string|null $defaultLayout Layout applied to every render unless overridden.
     *
     * @throws InvalidArgumentException If the directory does not exist.
     */
    public function __construct(
        string $directory,
        private readonly string $extension = '.php',
        private readonly ?string $defaultLayout = null,
    ) {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("Views directory \"{$directory}\" does not exist.");
        }

        $this->directory = $real;
    }

    /**
     * Shares a variable with every template.
     *
     * @param string $key   Variable name.
     * @param mixed  $value Variable value.
     *
     * @return static
     */
    public function share(string $key, mixed $value): static
    {
        $this->shared[$key] = $value;

        return $this;
    }

    /**
     * Tells whether a template exists.
     *
     * @param string $template Template name, e.g. "home" or "errors/404".
     *
     * @return bool
     */
    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Renders a template (wrapped in its layout, if any) and returns the HTML.
     *
     * @param string              $template Template name.
     * @param array<string,mixed> $data     Variables for the template.
     * @param string|null         $layout   Layout override; null uses the template's own or the default.
     *
     * @return string
     *
     * @throws InvalidArgumentException If a template cannot be found or its name is invalid.
     * @throws Throwable                Whatever the template throws.
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $data = array_replace($this->shared, $data);
        $context = new Template($this, $data);

        $content = $this->execute($this->resolve($template), $data, $context);

        $layout ??= $context->getLayout() ?? $this->defaultLayout;
        if ($layout === null) {
            return $content;
        }

        $data['content'] = $content;

        return $this->execute($this->resolve($layout), $data, new Template($this, $data));
    }

    /**
     * Renders a template without any layout.
     *
     * @param string              $template Template name.
     * @param array<string,mixed> $data     Variables for the template.
     *
     * @return string
     *
     * @throws InvalidArgumentException If the template cannot be found.
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $data = array_replace($this->shared, $data);

        return $this->execute($this->resolve($template), $data, new Template($this, $data));
    }

    /**
     * Maps a template name to a file inside the views directory.
     *
     * @param string $template Template name.
     *
     * @return string Absolute file path.
     *
     * @throws InvalidArgumentException On invalid names or missing files.
     */
    private function resolve(string $template): string
    {
        if (preg_match('#^[A-Za-z0-9_\-./]+$#', $template) !== 1
            || str_contains($template, '..')
            || str_starts_with($template, '/')) {
            throw new InvalidArgumentException("Invalid template name \"{$template}\".");
        }

        $name = str_ends_with($template, $this->extension) ? $template : $template . $this->extension;
        $file = realpath($this->directory . '/' . $name);

        if ($file === false || !is_file($file) || !str_starts_with($file, $this->directory . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Template \"{$template}\" not found.");
        }

        return $file;
    }

    /**
     * Includes a template file in an isolated scope and returns its output.
     *
     * @param string              $file    Absolute template path.
     * @param array<string,mixed> $data    Template variables.
     * @param Template            $context Object bound as `$this` inside the template.
     *
     * @return string
     *
     * @throws Throwable Whatever the template throws (the output buffer is cleaned first).
     */
    private function execute(string $file, array $data, Template $context): string
    {
        unset($data['this']);

        $run = function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        $bound = Closure::bind($run, $context, Template::class);

        ob_start();
        try {
            $bound($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
