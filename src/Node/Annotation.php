<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Note annotations base class.
 *
 * Port of gkeepapi/node.py `Annotation` (MIT, (c) Kai Zhong / kiwiz).
 */
class Annotation extends Element
{
    /** Annotation ID. */
    public ?string $id;

    public function __construct()
    {
        $this->id = $this->generateAnnotationId();
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = [];
        if ($this->id !== null) {
            $ret = parent::save($clean);
            $ret['id'] = $this->id;
        }

        return $ret;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->id = self::strOrNull(self::opt($raw, 'id'));
    }

    private function generateAnnotationId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0x00000000, 0xFFFFFFFF),
            random_int(0x0000, 0xFFFF),
            random_int(0x0000, 0xFFFF),
            random_int(0x0000, 0xFFFF),
            random_int(0x000000000000, 0xFFFFFFFFFFFF),
        );
    }
}
