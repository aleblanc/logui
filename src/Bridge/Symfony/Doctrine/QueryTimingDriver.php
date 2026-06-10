<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Doctrine;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class QueryTimingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly CurrentProfile $current)
    {
        parent::__construct($driver);
    }

    /** @param array<string,mixed> $params */
    public function connect(array $params): Connection
    {
        return new QueryTimingConnection(parent::connect($params), $this->current);
    }
}
