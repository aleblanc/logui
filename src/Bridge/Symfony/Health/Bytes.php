<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Health;

final class Bytes
{
    public static function format(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $exp = (int) min(floor(log($bytes, 1024)), \count($units) - 1);

        return round($bytes / (1024 ** $exp), 1).' '.$units[$exp];
    }
}
