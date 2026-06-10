<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Monolog;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Introspects registered Monolog loggers and returns the file destinations of their handlers.
 * Wrapper handlers (FingersCrossed/Filter/Buffer/...) are unwrapped; non-file handlers and
 * php:// streams (e.g. php://stderr in containers) are skipped.
 */
final class HandlerPathDiscovery
{
    /**
     * @param iterable<Logger> $loggers
     *
     * @return list<array{label:string,path:string}>
     */
    public function discover(iterable $loggers): array
    {
        $byPath = [];
        foreach ($loggers as $logger) {
            $channel = $logger->getName();
            foreach ($logger->getHandlers() as $handler) {
                $path = $this->filePath($this->unwrap($handler));
                if (null !== $path && !isset($byPath[$path])) {
                    $byPath[$path] = ['label' => $channel.' · '.basename($path), 'path' => $path];
                }
            }
        }

        return array_values($byPath);
    }

    private function unwrap(object $handler): object
    {
        // Unwrap Monolog wrapper handlers (FingersCrossed, Filter, Buffer, ...) via getHandler().
        $guard = 0;
        while ($guard++ < 10 && method_exists($handler, 'getHandler')) {
            $inner = $handler->getHandler();
            if (!\is_object($inner)) {
                break;
            }
            $handler = $inner;
        }

        return $handler;
    }

    private function filePath(object $handler): ?string
    {
        // RotatingFileHandler extends StreamHandler, so this covers both.
        if (!$handler instanceof StreamHandler) {
            return null;
        }

        $url = $handler->getUrl();
        if (!\is_string($url) || '' === $url || str_starts_with($url, 'php://')) {
            return null;
        }

        return $url;
    }
}
