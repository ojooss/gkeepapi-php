<?php

declare(strict_types=1);

namespace Gkeepapi;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Holder for the PSR-3 logger used by the library (replaces Python's module loggers).
 *
 * By default nothing is logged. Never log tokens, mail addresses or note contents.
 */
final class Log
{
    private static ?LoggerInterface $logger = null;

    private function __construct()
    {
    }

    /** Set the logger used by the library; null restores the silent default. */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /** Get the active logger. */
    public static function logger(): LoggerInterface
    {
        return self::$logger ?? new NullLogger();
    }
}
