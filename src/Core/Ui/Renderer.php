<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Ui;

final class Renderer
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new \InvalidArgumentException(sprintf('Invalid template name "%s".', $template));
        }

        // Prefixed name so a user-supplied var (e.g. "path") cannot collide with
        // it: extract($vars, EXTR_SKIP) below skips variables already in scope,
        // so any plain name here would silently shadow the value from $vars.
        $__logui_tpl = $this->templateDir.'/'.$template.'.php';
        if (!is_file($__logui_tpl)) {
            throw new \RuntimeException(sprintf('Template "%s" not found in "%s".', $template, $this->templateDir));
        }

        return (function () use ($__logui_tpl, $vars): string {
            extract($vars, \EXTR_SKIP);
            ob_start();

            try {
                require $__logui_tpl;
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            return (string) ob_get_clean();
        })();
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
