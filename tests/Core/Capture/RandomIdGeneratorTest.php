<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Capture;

use Aleblanc\LogUi\Core\Capture\IdGenerator;
use Aleblanc\LogUi\Core\Capture\RandomIdGenerator;
use PHPUnit\Framework\TestCase;

final class RandomIdGeneratorTest extends TestCase
{
    public function test_generates_non_empty_unique_hex_ids(): void
    {
        $generator = new RandomIdGenerator();

        self::assertInstanceOf(IdGenerator::class, $generator);
        $a = $generator->generate();
        $b = $generator->generate();

        // bin2hex(random_bytes(8)) → 16 lowercase hex chars.
        self::assertSame(16, \strlen($a));
        self::assertTrue(ctype_xdigit($a));
        self::assertNotSame($a, $b);
    }
}
