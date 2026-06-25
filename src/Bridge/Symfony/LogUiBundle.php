<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CommandProfilerListener;
use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Capture\RequestProfilerListener;
use Aleblanc\LogUi\Bridge\Symfony\Controller\HealthController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\RawLogController;
use Aleblanc\LogUi\Bridge\Symfony\Controller\UiController;
use Aleblanc\LogUi\Bridge\Symfony\Health\SystemHealthService;
use Aleblanc\LogUi\Bridge\Symfony\Health\VnstatService;
use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAuthCookieListener;
use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class LogUiBundle extends AbstractBundle
{
    /**
     * The bundle class lives at src/Bridge/Symfony, but config/ and templates/ live at the
     * package root, so @LogUiBundle must resolve to the package root (three levels up).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * Auto-wire the LogUI handler into Monolog so the host never has to edit monolog.yaml.
     * We prepend a `service` handler into the monolog config; MonologBundle then attaches it
     * to every channel itself (robust across versions, and visible in `debug:config monolog`).
     * The handler is always wired — capture_monolog gates it at runtime (so an env toggle works).
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('monolog')) {
            return;
        }

        // If the host already wired the handler by hand (older docs/recipe), don't add a second one.
        foreach ($builder->getExtensionConfig('monolog') as $config) {
            foreach ((array) ($config['handlers'] ?? []) as $handler) {
                if (\is_array($handler) && ($handler['id'] ?? null) === LogUiHandler::class) {
                    return;
                }
            }
        }

        $builder->prependExtensionConfig('monolog', [
            'handlers' => [
                'logui' => ['type' => 'service', 'id' => LogUiHandler::class],
            ],
        ]);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('telemetry_file')
                    ->defaultValue('%kernel.logs_dir%/%kernel.environment%.log')
                    ->info('The existing log file LogUI writes telemetry lines into and reads the request list back from.')
                ->end()
                ->integerNode('max_records_per_profile')->defaultValue(1000)->end()
                ->floatNode('slow_query_ms')->defaultValue(50.0)->end()
                ->scalarNode('ui_path')->defaultValue('/_logui')->end()
                ->enumNode('access')
                    ->values(['password', 'delegate'])
                    ->defaultValue('password')
                    ->info("'password': LogUI gate (dev/test open, prod LOGUI_PASSWORD). 'delegate': protect the route via your own firewall/role.")
                ->end()
                ->scalarNode('ui_password')
                    ->defaultValue('%env(LOGUI_PASSWORD)%')
                    ->info('Password for access=password in prod. Defaults to the LOGUI_PASSWORD env var the recipe generates.')
                ->end()
                ->arrayNode('ignore_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/_wdt', '/_profiler'])
                    ->info('Request path prefixes never profiled (the LogUI UI path is always added).')
                ->end()
                ->booleanNode('capture_monolog')
                    ->defaultTrue()
                    ->info('Auto-wire the LogUI handler onto every Monolog channel (no manual monolog.yaml edit). Set false (or via env) to disable capture, e.g. in prod.')
                ->end()
                ->booleanNode('discover_monolog')->defaultTrue()->end()
                ->arrayNode('log_dirs')
                    ->scalarPrototype()->end()
                    ->defaultValue(['%kernel.logs_dir%'])
                ->end()
                ->arrayNode('external_logs')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('redact_keys')
                    ->scalarPrototype()->end()
                    ->defaultValue(['password', 'passwd', 'secret', 'token', 'authorization', 'api_key'])
                ->end()
                ->arrayNode('health_services')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('systemd units shown on the Health tab via `systemctl is-active` (empty = no services section).')
                ->end()
            ->end();
    }

    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('logui.clock', SystemClock::class);
        $services->set('logui.memory', MemoryProbe::class);
        $services->set('logui.redactor', Redactor::class)->args([$config['redact_keys']]);

        $services->set('logui.factory', ProfileContextFactory::class)->args([
            new Reference('logui.clock'),
            new Reference('logui.redactor'),
            new Reference('logui.memory'),
            $config['max_records_per_profile'],
            $config['slow_query_ms'],
        ]);
        // Telemetry is appended directly to its own file (bypasses host Monolog routing) and read back from it.
        $services->set('logui.telemetry', TelemetryLogger::class)->args([$config['telemetry_file']]);
        $services->set('logui.telemetry_reader', TelemetryReader::class);

        $services->set('logui.current', CurrentProfile::class);

        $services->set('logui.listener.request', RequestProfilerListener::class)
            ->args([new Reference('logui.current'), new Reference('logui.factory'), new Reference('logui.telemetry'), array_merge([$config['ui_path']], $config['ignore_paths'])])
            ->tag('kernel.event_subscriber');
        $services->set('logui.listener.command', CommandProfilerListener::class)
            ->args([new Reference('logui.current'), new Reference('logui.factory'), new Reference('logui.telemetry')])
            ->tag('kernel.event_subscriber');

        // Public so monolog config can reference it: handlers: { logui: { type: service, id: ... } }.
        // It is auto-wired onto every channel via prependExtension(); capture_monolog gates it at runtime.
        $services->set(LogUiHandler::class)->args([new Reference('logui.current'), $config['capture_monolog']])->public();

        // ui_password defaults to %env(LOGUI_PASSWORD)%; provide an empty fallback so a missing
        // env var resolves to '' (guard then fail-closes) instead of throwing a container error.
        if (!$builder->hasParameter('env(LOGUI_PASSWORD)')) {
            $builder->setParameter('env(LOGUI_PASSWORD)', '');
        }
        $services->set('logui.guard', UiAccessGuard::class)->args(['%kernel.environment%', $config['ui_password'], $config['access'], $config['ui_path']]);
        // Keeps password-mode users logged in across navigation (mints a session cookie on success).
        $services->set('logui.auth_cookie_listener', UiAuthCookieListener::class)
            ->args([new Reference('logui.guard'), $config['ui_path']])
            ->tag('kernel.event_subscriber');

        $services->set(UiController::class)
            ->args([new Reference('logui.telemetry_reader'), $config['telemetry_file'], new Reference('twig'), new Reference('logui.guard'), $config['ui_path']])
            ->public()
            ->tag('controller.service_arguments');

        // Raw-logs tab: Monolog file auto-discovery + configured external_logs.
        $services->set('logui.discovery', HandlerPathDiscovery::class);
        $services->set('logui.plainreader', PlainLogReader::class);
        $services->set('logui.rawlog_sources', RawLogSources::class)
            ->args([new Reference('logui.discovery'), [new Reference('logger')], $config['external_logs'], $config['log_dirs'], $config['discover_monolog'], '%kernel.project_dir%']);
        $services->set(RawLogController::class)
            ->args([new Reference('logui.rawlog_sources'), new Reference('logui.plainreader'), new Reference('twig'), new Reference('logui.guard'), $config['ui_path']])
            ->public()
            ->tag('controller.service_arguments');

        // Health tab: read-only host metrics (degrade gracefully when a probe is unavailable).
        $services->set('logui.vnstat', VnstatService::class);
        $services->set('logui.health', SystemHealthService::class)
            ->args([$config['health_services'], new Reference('logui.vnstat')]);
        $services->set(HealthController::class)
            ->args([new Reference('logui.health'), new Reference('twig'), new Reference('logui.guard'), $config['ui_path']])
            ->public()
            ->tag('controller.service_arguments');

        // SQL query counting — only when Doctrine DBAL is installed in the host app.
        if (interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
            $services->set('logui.doctrine_middleware', Doctrine\QueryTimingMiddleware::class)
                ->args([new Reference('logui.current')])
                ->tag('doctrine.middleware');
        }
    }
}
