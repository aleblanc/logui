<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Model;

enum ProfileType: string
{
    case Http = 'http';
    case Cli = 'cli';
}
