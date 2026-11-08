<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Collaborator change type.
 *
 * Port of gkeepapi/node.py `ShareRequestValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum ShareRequestValue: string
{
    /** Grant access. */
    case Add = 'WR';

    /** Remove access. */
    case Remove = 'RM';
}
