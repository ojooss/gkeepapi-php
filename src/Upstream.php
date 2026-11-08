<?php

declare(strict_types=1);

namespace Gkeepapi;

/**
 * Metadata about the upstream Python library this package is ported from.
 *
 * Keep in sync with UPSTREAM.md.
 */
final class Upstream
{
    public const REPOSITORY = 'https://github.com/kiwiz/gkeepapi';
    public const VERSION = '0.17.1';
    public const COMMIT = '1a94b25c18a7abfdc23d1412091129cd63652877';

    private function __construct()
    {
    }
}
