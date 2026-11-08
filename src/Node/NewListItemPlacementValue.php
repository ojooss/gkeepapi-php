<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Target location to put new list items.
 *
 * Port of gkeepapi/node.py `NewListItemPlacementValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum NewListItemPlacementValue: string
{
    /** Top */
    case Top = 'TOP';

    /** Bottom */
    case Bottom = 'BOTTOM';
}
