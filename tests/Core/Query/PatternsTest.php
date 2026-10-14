<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\Patterns;
use PHPUnit\Framework\TestCase;

final class PatternsTest extends TestCase
{
    public function test_normalize_replaces_the_variable_parts_with_placeholders(): void
    {
        self::assertSame(
            'User <n> logged in from <ip> (session <uuid>)',
            Patterns::normalize('User 42 logged in from 10.0.0.7 (session 3fa85f64-5717-4562-b3fc-2c963f66afa6)'),
        );
    }

    public function test_normalize_replaces_a_quoted_string(): void
    {
        self::assertSame('deleting file <str>', Patterns::normalize('deleting file "orders-2026.csv"'));
    }

    public function test_normalize_keeps_only_the_first_line(): void
    {
        self::assertSame('boom', Patterns::normalize("boom\n#0 /app/src/X.php(10): foo()"));
    }

    public function test_two_messages_differing_by_a_number_are_one_pattern(): void
    {
        $patterns = new Patterns();
        $patterns->add('User 42 logged in', 'info');
        $patterns->add('User 99 logged in', 'warning');

        $top = $patterns->top(10);

        self::assertCount(1, $top);
        self::assertSame('User <n> logged in', $top[0]['pattern']);
        self::assertSame(2, $top[0]['count']);
        self::assertSame('warning', $top[0]['level'], 'worst level seen wins');
    }

    public function test_overflow_is_folded_into_other(): void
    {
        $patterns = new Patterns();
        for ($i = 0; $i < Patterns::MAX_DISTINCT + 1; ++$i) {
            // Purely alphabetic tokens (digits mapped to letters) so each normalises to a distinct
            // pattern — a numeric suffix would collapse them all to one `<n>`.
            $patterns->add('event kind '.strtr((string) $i, '0123456789', 'ghijklmnop'), 'info');
        }

        $keys = array_column($patterns->top(Patterns::MAX_DISTINCT + 10), 'pattern');

        self::assertContains('<other>', $keys);
    }
}
