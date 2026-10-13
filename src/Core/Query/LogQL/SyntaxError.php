<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/** A query the parser could not understand, with the offending position. */
final class SyntaxError extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $position = 0)
    {
        parent::__construct($message);
    }
}
