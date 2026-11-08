<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Valid blob types.
 *
 * Port of gkeepapi/node.py `BlobType` (MIT, (c) Kai Zhong / kiwiz).
 */
enum BlobType: string
{
    /** Audio */
    case Audio = 'AUDIO';

    /** Image */
    case Image = 'IMAGE';

    /** Drawing */
    case Drawing = 'DRAWING';
}
