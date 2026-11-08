<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Node\Element;
use Gkeepapi\Node\Node;
use PHPUnit\Framework\TestCase;

abstract class NodeTestCase extends TestCase
{
    /**
     * Constructs an empty object and clones it from the serialized representation.
     *
     * @param callable(): Element $factory
     */
    protected static function assertSaveLoadRoundTrips(callable $factory): void
    {
        $a = $factory();
        $b = $factory();
        $b->load($a->save());

        self::assertEquals($a->save(), $b->save());
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    protected static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    protected static function cleanNode(Element $n): Element
    {
        $n->save();
        if ($n instanceof Node) {
            foreach ($n->getChildren() as $child) {
                $child->save();
            }
        }
        self::assertFalse($n->isDirty());

        return $n;
    }
}
