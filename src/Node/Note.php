<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a Google Keep note.
 *
 * Port of gkeepapi/node.py `Note` (MIT, (c) Kai Zhong / kiwiz).
 */
class Note extends TopLevelNode implements \Stringable
{
    public function __construct(?string $id = null)
    {
        parent::__construct($id, NodeType::Note);
    }

    /** @inheritDoc */
    public function getText(): string
    {
        $node = $this->getTextNode();

        return $node?->getText() ?? $this->text;
    }

    /** @inheritDoc */
    public function setText(string $value): void
    {
        $node = $this->getTextNode();
        if (!$node instanceof ListItem) {
            $node = new ListItem($this->id);
            $this->append($node, true);
        }
        $node->setText($value);
        $this->touch(true);
    }

    public function __toString(): string
    {
        return $this->getTitle() . "\n" . $this->getText();
    }

    private function getTextNode(): ?ListItem
    {
        foreach ($this->getChildren() as $child) {
            if ($child instanceof ListItem) {
                return $child;
            }
        }

        return null;
    }
}
