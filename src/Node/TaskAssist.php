<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Unknown.
 *
 * Port of gkeepapi/node.py `TaskAssist` (MIT, (c) Kai Zhong / kiwiz).
 */
class TaskAssist extends Annotation
{
    private ?string $suggest = null;

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['taskAssist'] = ['suggestType' => $this->suggest];

        return $ret;
    }

    /** Get the suggestion. */
    public function getSuggest(): ?string
    {
        return $this->suggest;
    }

    /** Set the suggestion. */
    public function setSuggest(string $value): void
    {
        $this->suggest = $value;
        $this->dirty = true;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $assist = self::reqArray($raw, 'taskAssist');
        $this->suggest = self::strOrNull(self::req($assist, 'suggestType'));
    }
}
