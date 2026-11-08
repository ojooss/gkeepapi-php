<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Collaborator role type.
 *
 * Port of gkeepapi/node.py `RoleValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum RoleValue: string
{
    /** Note owner. */
    case Owner = 'O';

    /** Note collaborator. */
    case User = 'W';
}
