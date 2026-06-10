<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

final class RandomIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(8));
    }
}
