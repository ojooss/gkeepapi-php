<?php

declare(strict_types=1);

namespace Gkeepapi;

/**
 * A regular expression query for {@see Keep::find()} and {@see Keep::findLabel()}.
 *
 * Replaces Python's `re.Pattern`. The pattern is a PCRE pattern including delimiters, e.g. `/^todo/i`.
 */
final readonly class Pattern
{
    /**
     * @throws \InvalidArgumentException If the pattern is not a valid PCRE pattern.
     */
    public function __construct(public string $pattern)
    {
        set_error_handler(static fn (): bool => true);
        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new \InvalidArgumentException('Invalid regular expression');
        }
    }

    /** Whether the pattern matches anywhere in the subject (Python `Pattern.search`). */
    public function search(string $subject): bool
    {
        return preg_match($this->pattern, $subject) === 1;
    }
}
