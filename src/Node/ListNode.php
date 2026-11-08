<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a Google Keep list.
 *
 * Named `ListNode` because `List` is a reserved word in PHP (upstream: `List`).
 *
 * Port of gkeepapi/node.py `List` (MIT, (c) Kai Zhong / kiwiz).
 */
class ListNode extends TopLevelNode implements \Stringable
{
    public const SORT_DELTA = 10000; // Arbitrary constant

    public function __construct(?string $id = null)
    {
        parent::__construct($id, NodeType::List);
    }

    /**
     * Generate a list of sorted list items, taking into account parent items.
     *
     * @param list<ListItem> $items Items to sort.
     * @return list<ListItem> Sorted items (descending, subitems directly after their parent).
     */
    public static function sortedItems(array $items): array
    {
        $keyed = array_map(
            static fn (ListItem $item): array => [
                $item->parentItem instanceof ListItem
                    ? [$item->parentItem->getSort(), $item->getSort()]
                    : [$item->getSort()],
                $item,
            ],
            $items,
        );
        // Descending order (Python: reverse=True); the sort is stable.
        usort($keyed, static fn (array $a, array $b): int => self::compareKeys($b[0], $a[0]));

        return array_map(static fn (array $pair): ListItem => $pair[1], $keyed);
    }

    /**
     * Add a new item to the list.
     *
     * @param string $text The text.
     * @param bool $checked Whether this item is checked.
     * @param NewListItemPlacementValue|int|null $sort Item id for sorting or a placement policy.
     */
    public function add(string $text, bool $checked = false, NewListItemPlacementValue|int|null $sort = null): ListItem
    {
        $node = new ListItem($this->id, $this->serverId);
        $node->setChecked($checked);
        $node->setText($text);

        $items = $this->getItems();
        if (is_int($sort)) {
            $node->setSort($sort);
        } elseif ($sort instanceof NewListItemPlacementValue && $items !== []) {
            $sorts = array_map(static fn (ListItem $item): int => $item->getSort(), $items);
            if ($sort === NewListItemPlacementValue::Bottom) {
                $node->setSort(min($sorts) - self::SORT_DELTA);
            } else {
                $node->setSort(max($sorts) + self::SORT_DELTA);
            }
        }

        $this->append($node, true);
        $this->touch(true);

        return $node;
    }

    /** @inheritDoc */
    public function getText(): string
    {
        return implode("\n", array_map(strval(...), $this->getItems()));
    }

    /**
     * Sort list items in place. By default, the items are alphabetized, but a custom function can be specified.
     *
     * @param (callable(ListItem): mixed)|null $key Maps an item to its sort key (default: its text).
     * @param bool $reverse Whether to reverse the output.
     */
    public function sortItems(?callable $key = null, bool $reverse = false): void
    {
        $key ??= static fn (ListItem $item): string => $item->getText();
        $keyed = array_map(static fn (ListItem $item): array => [$key($item), $item], $this->listItems());
        usort(
            $keyed,
            static fn (array $a, array $b): int => $reverse ? $b[0] <=> $a[0] : $a[0] <=> $b[0],
        );

        $sortValue = random_int(1000000000, 9999999999);
        foreach ($keyed as [, $node]) {
            $node->setSort($sortValue);
            $sortValue -= self::SORT_DELTA;
        }
    }

    public function __toString(): string
    {
        return implode("\n", array_merge([$this->getTitle()], array_map(strval(...), $this->getItems())));
    }

    /**
     * Get all listitems.
     *
     * @return list<ListItem>
     */
    public function getItems(): array
    {
        return self::sortedItems($this->listItems());
    }

    /**
     * Get all checked listitems.
     *
     * @return list<ListItem>
     */
    public function getChecked(): array
    {
        return self::sortedItems($this->listItems(true));
    }

    /**
     * Get all unchecked listitems.
     *
     * @return list<ListItem>
     */
    public function getUnchecked(): array
    {
        return self::sortedItems($this->listItems(false));
    }

    /**
     * @param array<array-key, int> $a
     * @param array<array-key, int> $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        $count = max(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $x = $a[$i] ?? null;
            $y = $b[$i] ?? null;
            if ($x !== $y) {
                if ($x === null) {
                    return 1;
                }
                if ($y === null) {
                    return -1;
                }

                return $x <=> $y;
            }
        }

        return 0;
    }

    /**
     * @return list<ListItem>
     */
    private function listItems(?bool $checked = null): array
    {
        $items = [];
        foreach ($this->getChildren() as $node) {
            if (!$node instanceof ListItem || $node->isDeleted()) {
                continue;
            }
            if ($checked === null || $node->isChecked() === $checked) {
                $items[] = $node;
            }
        }

        return $items;
    }
}
