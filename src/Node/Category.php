<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a category annotation on a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `Category` (MIT, (c) Kai Zhong / kiwiz).
 */
class Category extends Annotation
{
    private ?CategoryValue $category = null;

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['topicCategory'] = ['category' => $this->category?->value];

        return $ret;
    }

    /** Get the category. */
    public function getCategory(): ?CategoryValue
    {
        return $this->category;
    }

    /** Set the category. */
    public function setCategory(CategoryValue $value): void
    {
        $this->category = $value;
        $this->dirty = true;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $topic = self::reqArray($raw, 'topicCategory');
        $this->category = CategoryValue::from(self::str(self::req($topic, 'category')));
    }
}
