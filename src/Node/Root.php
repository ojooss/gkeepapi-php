<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Internal root node.
 *
 * Port of gkeepapi/node.py `Root` (MIT, (c) Kai Zhong / kiwiz).
 */
class Root extends Node
{
    public const ID = 'root';

    public function __construct()
    {
        parent::__construct(self::ID);
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        return false;
    }
}
