<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * The `| json` stage: exposes the record's JSON context as labels for later filters.
 *
 * LogUI already extracts the context/extra JSON while parsing the line, so this stage costs
 * nothing extra beyond decoding — no second pass over the raw text.
 */
final class JsonParser implements Stage
{
}
