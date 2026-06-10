<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use PHPUnit\Framework\TestCase;

final class CurrentProfileTest extends TestCase
{
    private function factory(): ProfileContextFactory
    {
        return new ProfileContextFactory(new SystemClock(), new Redactor([]), new MemoryProbe(), 1000, 50.0);
    }

    public function test_starts_empty_and_is_null_safe(): void
    {
        $current = new CurrentProfile();

        self::assertFalse($current->isActive());
        // No active context → these must not throw.
        $current->addRecord('error', 'app', 'ignored', []);
        self::assertNull($current->finish(200));
    }

    public function test_record_query_forwards_to_active_context(): void
    {
        $current = new CurrentProfile();
        $current->begin($this->factory()->create('r1', ProfileType::Http, 'GET /'));

        $current->recordQuery('SELECT 1', 81.0);

        self::assertSame(1, $current->finish(200)?->queries->count);
    }

    public function test_begin_then_finish_returns_profile(): void
    {
        $current = new CurrentProfile();
        $current->begin($this->factory()->create('r1', ProfileType::Http, 'GET /'));
        $current->addRecord('warning', 'app', 'hi', []);

        self::assertTrue($current->isActive());
        $profile = $current->finish(200);

        self::assertNotNull($profile);
        self::assertSame('r1', $profile->id);
        self::assertSame(['warning' => 1], $profile->levels);
        self::assertFalse($current->isActive(), 'finish clears the holder');
    }
}
