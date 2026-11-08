<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Visibility setting for the graveyard.
 *
 * Port of gkeepapi/node.py `GraveyardStateValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum GraveyardStateValue: string
{
    /** Expanded */
    case Expanded = 'EXPANDED';

    /** Collapsed */
    case Collapsed = 'COLLAPSED';
}
