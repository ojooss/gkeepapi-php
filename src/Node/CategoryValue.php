<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Valid note categories.
 *
 * Port of gkeepapi/node.py `CategoryValue` (MIT, (c) Kai Zhong / kiwiz).
 */
enum CategoryValue: string
{
    /** Books */
    case Books = 'BOOKS';

    /** Food */
    case Food = 'FOOD';

    /** Movies */
    case Movies = 'MOVIES';

    /** Music */
    case Music = 'MUSIC';

    /** Places */
    case Places = 'PLACES';

    /** Quotes */
    case Quotes = 'QUOTES';

    /** Travel */
    case Travel = 'TRAVEL';

    /** TV */
    case TV = 'TV';
}
