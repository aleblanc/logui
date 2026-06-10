<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Log;

use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Monolog\Logger;

/**
 * The set of raw `.log` files LogUI exposes read-only: those auto-discovered from the host's
 * Monolog handlers, plus any explicitly configured `external_logs` paths (deduplicated).
 */
final class RawLogSources
{
    /**
     * @param iterable<Logger> $loggers
     * @param list<string>     $configuredPaths
     * @param list<string>     $logDirs         directories scanned for `*.log` files
     */
    public function __construct(
        private readonly HandlerPathDiscovery $discovery,
        private readonly iterable $loggers,
        private readonly array $configuredPaths,
        private readonly array $logDirs = [],
        private readonly bool $discoverMonolog = true,
    ) {
    }

    /** @return list<array{label:string,path:string}> */
    public function all(): array
    {
        $byPath = [];

        // 1) Files declared in the Monolog handler config (precise, with channel labels).
        if ($this->discoverMonolog) {
            foreach ($this->discovery->discover($this->loggers) as $entry) {
                $byPath[$entry['path']] = $entry;
            }
        }

        // 2) Every *.log found in the configured log directories (catches web-server logs,
        //    other channels, rotated files — anything Monolog handler introspection misses).
        foreach ($this->logDirs as $dir) {
            foreach (glob(rtrim($dir, '/').'/*.log') ?: [] as $path) {
                if (!isset($byPath[$path])) {
                    $byPath[$path] = ['label' => basename($path), 'path' => $path];
                }
            }
        }

        // 3) Explicitly configured external_logs (e.g. files outside the scanned dirs).
        foreach ($this->configuredPaths as $path) {
            if (!isset($byPath[$path])) {
                $byPath[$path] = ['label' => basename($path), 'path' => $path];
            }
        }

        return array_values($byPath);
    }

    public function knows(string $path): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry['path'] === $path) {
                return true;
            }
        }

        return false;
    }
}
