<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Valid note types.
 *
 * Port of gkeepapi/node.py `NodeType` (MIT, (c) Kai Zhong / kiwiz).
 */
enum NodeType: string
{
    /** A Note */
    case Note = 'NOTE';

    /** A List */
    case List = 'LIST';

    /** A List item */
    case ListItem = 'LIST_ITEM';

    /** A blob (attachment) */
    case Blob = 'BLOB';
}
