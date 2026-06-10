<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Doctrine;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Doctrine\QueryTimingStatement;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use PHPUnit\Framework\TestCase;

final class QueryTimingMiddlewareTest extends TestCase
{
    public function test_statement_execute_records_a_query(): void
    {
        $current = new CurrentProfile();
        $current->begin(
            (new ProfileContextFactory(new SystemClock(), new Redactor([]), new MemoryProbe(), 1000, 50.0))
                ->create('r', ProfileType::Http, 'GET /')
        );

        $inner = $this->createMock(DriverStatement::class);
        $inner->method('execute')->willReturn($this->createMock(Result::class));

        $stmt = new QueryTimingStatement($inner, 'SELECT 1', $current);
        $stmt->execute();

        self::assertSame(1, $current->finish(200)?->queries->count);
    }

    public function test_statement_records_even_when_no_profile_active(): void
    {
        $inner = $this->createMock(DriverStatement::class);
        $inner->method('execute')->willReturn($this->createMock(Result::class));

        // No active profile → recordQuery is a no-op, must not throw.
        (new QueryTimingStatement($inner, 'SELECT 1', new CurrentProfile()))->execute();

        $this->expectNotToPerformAssertions();
    }
}
