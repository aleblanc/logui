<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Model;

final class LogLevel
{
    /** @var array<string,int> */
    private const ORDER = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    public static function severity(string $level): int
    {
        return self::ORDER[strtolower($level)] ?? 0;
    }

    /** @return list<string> levels from lowest to highest severity */
    public static function all(): array
    {
        return array_keys(self::ORDER);
    }
}
