<?php

declare(strict_types=1);

namespace Gkeepapi\Exception;

/**
 * Browser login required error.
 *
 * Port of gkeepapi/exception.py (MIT, (c) Kai Zhong / kiwiz).
 */
class BrowserLoginRequiredException extends LoginException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('Browser login required');
    }
}
