<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Exception\InvalidException;

/**
 * Represents a Google Keep listitem.
 *
 * Interestingly enough, {@see Note}s store their content in a single child ListItem.
 *
 * Port of gkeepapi/node.py `ListItem` (MIT, (c) Kai Zhong / kiwiz).
 */
class ListItem extends Node implements \Stringable
{
    /** Parent list item this item is indented under. */
    public ?ListItem $parentItem = null;

    /** Previous super list item ID. */
    public ?string $prevSuperListItemId = null;

    /** @var array<string, ListItem> */
    private array $subitems = [];

    private bool $checked = false;

    public function __construct(
        ?string $parentId = null,
        /** Server-side ID of the parent. */
        public ?string $parentServerId = null,
        /** ID of the super list item (indentation). */
        public ?string $superListItemId = null,
        ?string $id = null,
    ) {
        parent::__construct($id, NodeType::ListItem, $parentId);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['parentServerId'] = $this->parentServerId;
        $ret['superListItemId'] = $this->superListItemId;
        $ret['checked'] = $this->checked;

        return $ret;
    }

    /**
     * Add a new sub item to the list. This item must already be attached to a list.
     *
     * @param string $text The text.
     * @param bool $checked Whether this item is checked.
     * @param NewListItemPlacementValue|int|null $sort Item id for sorting.
     * @throws InvalidException If the item has no parent list.
     */
    public function add(string $text, bool $checked = false, NewListItemPlacementValue|int|null $sort = null): ListItem
    {
        if (!$this->parent instanceof ListNode) {
            throw new InvalidException('Item has no parent');
        }
        $node = $this->parent->add($text, $checked, $sort);
        $this->indent($node);

        return $node;
    }

    /**
     * Indent an item. Does nothing if the target has subitems.
     *
     * @param bool $dirty Whether this node should be marked dirty.
     */
    public function indent(ListItem $node, bool $dirty = true): void
    {
        if ($node->getSubitems() !== []) {
            return;
        }
        $this->subitems[$node->id] = $node;
        $node->superListItemId = $this->id;
        $node->parentItem = $this;
        if ($dirty) {
            $node->touch(true);
        }
    }

    /**
     * Dedent an item. Does nothing if the target is not indented under this item.
     *
     * @param bool $dirty Whether this node should be marked dirty.
     */
    public function dedent(ListItem $node, bool $dirty = true): void
    {
        if (!array_key_exists($node->id, $this->subitems)) {
            return;
        }
        unset($this->subitems[$node->id]);
        $node->superListItemId = '';
        $node->parentItem = null;
        if ($dirty) {
            $node->touch(true);
        }
    }

    /**
     * Get subitems for this item.
     *
     * @return list<ListItem>
     */
    public function getSubitems(): array
    {
        return ListNode::sortedItems(array_values($this->subitems));
    }

    /** Get indentation state. */
    public function isIndented(): bool
    {
        return $this->parentItem instanceof ListItem;
    }

    /** Get the checked state. */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /** Set the checked state. */
    public function setChecked(bool $value): void
    {
        $this->checked = $value;
        $this->touch(true);
    }

    public function __toString(): string
    {
        return ($this->isIndented() ? '  ' : '') . ($this->checked ? '☑' : '☐') . ' ' . $this->getText();
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->prevSuperListItemId = $this->superListItemId;
        $superListItemId = self::strOrNull(self::opt($raw, 'superListItemId'));
        $this->superListItemId = $superListItemId === '' ? null : $superListItemId;
        $this->checked = (bool) self::opt($raw, 'checked', false);
    }
}
