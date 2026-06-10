<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Doctrine;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware that times every SQL statement and feeds the active LogUI profile.
 * Registered with the `doctrine.middleware` tag; self-disabled by the bundle when DBAL is absent.
 */
final class QueryTimingMiddleware implements Middleware
{
    public function __construct(private readonly CurrentProfile $current)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new QueryTimingDriver($driver, $this->current);
    }
}
