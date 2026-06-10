<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony;

use Aleblanc\LogUi\Bridge\Symfony\LogUiBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function process(array $config = []): array
    {
        $extension = (new LogUiBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, ['log_ui' => $config]);
    }

    public function test_ui_password_defaults_to_the_generated_env_var(): void
    {
        // Regression: a null default meant the recipe-generated LOGUI_PASSWORD was never read,
        // so prod stayed permanently fail-closed even with a correct ?_pw=.
        self::assertSame('%env(LOGUI_PASSWORD)%', $this->process()['ui_password']);
    }

    public function test_capture_monolog_defaults_to_true(): void
    {
        self::assertTrue($this->process()['capture_monolog']);
    }

    public function test_access_defaults_to_password(): void
    {
        self::assertSame('password', $this->process()['access']);
    }

    public function test_an_explicit_password_overrides_the_default(): void
    {
        self::assertSame('literal', $this->process(['ui_password' => 'literal'])['ui_password']);
    }
}
