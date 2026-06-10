<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Capture;

use Aleblanc\LogUi\Core\Capture\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_masks_sensitive_top_level_keys(): void
    {
        $redactor = new Redactor(['password', 'token']);

        $out = $redactor->redact(['user' => 'alice', 'password' => 'hunter2']);

        self::assertSame('alice', $out['user']);
        self::assertSame('***', $out['password']);
    }

    public function test_is_case_insensitive(): void
    {
        $redactor = new Redactor(['authorization']);

        $out = $redactor->redact(['Authorization' => 'Bearer x']);

        self::assertSame('***', $out['Authorization']);
    }

    public function test_redacts_nested_arrays(): void
    {
        $redactor = new Redactor(['secret']);

        $out = $redactor->redact(['payload' => ['secret' => 'x', 'ok' => 1]]);

        self::assertSame('***', $out['payload']['secret']);
        self::assertSame(1, $out['payload']['ok']);
    }

    public function test_stops_recursing_at_max_depth(): void
    {
        $redactor = new Redactor(['secret'], maxDepth: 2);

        $out = $redactor->redact(['a' => ['b' => ['c' => ['secret' => 'deep']]]]);

        // At depth 2 the further-nested array is replaced by a placeholder rather than recursed.
        self::assertSame('[nested]', $out['a']['b']['c']);
    }

    public function test_integer_keyed_values_pass_through(): void
    {
        $redactor = new Redactor(['authorization']);

        // A list of raw header strings has no named key to match, so it is left as-is by design.
        $out = $redactor->redact(['headers' => ['Authorization: Bearer x']]);

        self::assertSame(['Authorization: Bearer x'], $out['headers']);
    }
}
