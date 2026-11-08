<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents an audio blob.
 *
 * Port of gkeepapi/node.py `NodeAudio` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeAudio extends NodeBlob
{
    private ?int $length = null;

    public function __construct()
    {
        parent::__construct(BlobType::Audio);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        if ($this->length !== null) {
            $ret['length'] = $this->length;
        }

        return $ret;
    }

    /** Get length of the audio clip. */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->length = self::intOrNull(self::opt($raw, 'length'));
    }
}
