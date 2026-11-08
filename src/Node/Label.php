<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use DateTimeImmutable;

/**
 * Represents a label.
 *
 * Port of gkeepapi/node.py `Label` (MIT, (c) Kai Zhong / kiwiz).
 */
class Label extends Element implements \Stringable
{
    use TimestampsMixin;

    /** Label ID. */
    public string $id;

    private string $name = '';
    private DateTimeImmutable $merged;

    public function __construct()
    {
        $createTime = microtime(true);
        $this->id = $this->generateId($createTime);
        $this->timestamps = new NodeTimestamps($createTime);
        $this->merged = NodeTimestamps::intToDt(0);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['mainId'] = $this->id;
        $ret['name'] = $this->name;
        $ret['timestamps'] = $this->timestamps->save($clean);
        $ret['lastMerged'] = NodeTimestamps::dtToStr($this->merged);

        return $ret;
    }

    /** Get the label name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Set the label name. */
    public function setName(string $value): void
    {
        $this->name = $value;
        $this->touch(true);
    }

    /** Get last merge datetime. */
    public function getMerged(): DateTimeImmutable
    {
        return $this->merged;
    }

    /** Set last merge datetime. */
    public function setMerged(DateTimeImmutable $value): void
    {
        $this->merged = $value;
        $this->touch();
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        return parent::isDirty() || $this->timestamps->isDirty();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->id = self::str(self::req($raw, 'mainId'));
        $this->name = self::str(self::req($raw, 'name'));
        $this->timestamps->load(self::reqArray($raw, 'timestamps'));
        $this->merged = NodeTimestamps::strToDt(self::strOrNull(self::opt($raw, 'lastMerged')));
    }

    private function generateId(float $tz): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $random = '';
        for ($i = 0; $i < 12; $i++) {
            $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return sprintf('tag.%s.%x', $random, (int) ($tz * 1000));
    }
}
