<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

interface IdGenerator
{
    public function generate(): string;
}
