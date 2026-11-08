<?php

declare(strict_types=1);

namespace Gkeepapi\Exception;

/**
 * Parse error.
 *
 * Port of gkeepapi/exception.py (MIT, (c) Kai Zhong / kiwiz).
 */
class ParseException extends KeepException
{
    /**
     * @param array<array-key, mixed> $raw The raw payload that failed to parse.
     */
    public function __construct(string $msg, public readonly array $raw, ?\Throwable $previous = null)
    {
        parent::__construct($msg, 0, $previous);
    }
}
