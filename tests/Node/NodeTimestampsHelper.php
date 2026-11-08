<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

/**
 * Test helper: upstream recordings carry millisecond timestamps, the library writes microseconds.
 */
final class NodeTimestampsHelper
{
    private function __construct()
    {
    }

    /** Reduce `2020-02-27T04:14:18.261000Z` to `2020-02-27T04:14:18.261Z`. */
    public static function normalise(string $timestamp): string
    {
        return (string) preg_replace('/\.(\d{3})\d{3}Z$/', '.$1Z', $timestamp);
    }
}
