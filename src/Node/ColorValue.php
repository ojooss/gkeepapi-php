<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Valid note colors.
 *
 * Port of gkeepapi/node.py `ColorValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum ColorValue: string
{
    /** White */
    case White = 'DEFAULT';

    /** Red */
    case Red = 'RED';

    /** Orange */
    case Orange = 'ORANGE';

    /** Yellow */
    case Yellow = 'YELLOW';

    /** Green */
    case Green = 'GREEN';

    /** Teal */
    case Teal = 'TEAL';

    /** Blue */
    case Blue = 'BLUE';

    /** Dark blue */
    case DarkBlue = 'CERULEAN';

    /** Purple */
    case Purple = 'PURPLE';

    /** Pink */
    case Pink = 'PINK';

    /** Brown */
    case Brown = 'BROWN';

    /** Gray */
    case Gray = 'GRAY';
}
