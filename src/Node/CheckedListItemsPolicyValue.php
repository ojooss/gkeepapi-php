<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Movement setting for checked list items.
 *
 * Port of gkeepapi/node.py `CheckedListItemsPolicyValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum CheckedListItemsPolicyValue: string
{
    /** Default */
    case Default = 'DEFAULT';

    /** Graveyard */
    case Graveyard = 'GRAVEYARD';
}
