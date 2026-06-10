<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Doctrine;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class QueryTimingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly string $sql,
        private readonly CurrentProfile $current,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $start = microtime(true);
        try {
            return parent::execute();
        } finally {
            $this->current->recordQuery($this->sql, (microtime(true) - $start) * 1000);
        }
    }
}
