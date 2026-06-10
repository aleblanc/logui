<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Ui;

use Aleblanc\LogUi\Core\Ui\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function test_renders_template_with_vars(): void
    {
        $renderer = new Renderer(__DIR__.'/fixtures');

        $html = $renderer->render('hello', ['name' => 'Alice']);

        self::assertStringContainsString('Hello Alice!', $html);
    }

    public function test_escape_encodes_html(): void
    {
        $renderer = new Renderer(__DIR__.'/fixtures');

        $html = $renderer->render('hello', ['name' => '<script>']);

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_unknown_template_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Renderer(__DIR__.'/fixtures'))->render('does-not-exist');
    }

    public function test_rejects_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Renderer(__DIR__.'/fixtures'))->render('../hello');
    }

    public function test_vars_are_not_shadowed_by_internal_variables(): void
    {
        $renderer = new Renderer(__DIR__.'/fixtures');

        // A var named "path" collides with the internal template-path variable.
        // It must reach the template unchanged, not be silenced by EXTR_SKIP.
        $html = $renderer->render('echo_path', ['path' => '/var/log/app.log']);

        self::assertStringContainsString('path=/var/log/app.log', $html);
        self::assertStringNotContainsString('echo_path.php', $html);
    }
}
