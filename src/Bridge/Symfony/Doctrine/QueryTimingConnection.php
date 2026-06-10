<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Doctrine;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class QueryTimingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly CurrentProfile $current)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new QueryTimingStatement(parent::prepare($sql), $sql, $this->current);
    }

    public function query(string $sql): Result
    {
        $start = microtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->current->recordQuery($sql, (microtime(true) - $start) * 1000);
        }
    }

    public function exec(string $sql): int|string
    {
        $start = microtime(true);
        try {
            return parent::exec($sql);
        } finally {
            $this->current->recordQuery($sql, (microtime(true) - $start) * 1000);
        }
    }
}
