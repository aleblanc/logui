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

        $path = $this->templateDir.'/'.$template.'.php';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Template "%s" not found in "%s".', $template, $this->templateDir));
        }

        return (function () use ($path, $vars): string {
            extract($vars, \EXTR_SKIP);
            ob_start();

            try {
                require $path;
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
