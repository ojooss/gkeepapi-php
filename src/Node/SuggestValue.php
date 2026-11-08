<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Valid task suggestion categories.
 *
 * Port of gkeepapi/node.py `SuggestValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum SuggestValue: string
{
    /** Grocery item */
    case GroceryItem = 'GROCERY_ITEM';
}
