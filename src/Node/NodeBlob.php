<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a blob descriptor.
 *
 * Port of gkeepapi/node.py `NodeBlob` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeBlob extends Element
{
    /** Blob ID. */
    public ?string $blobId = null;

    protected ?string $mediaId = null;
    protected ?string $mimetype = '';

    public function __construct(
        /** Blob type. */
        public ?BlobType $type = null
    ) {
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['kind'] = 'notes#blob';
        $ret['type'] = $this->type?->value;
        if ($this->blobId !== null) {
            $ret['blob_id'] = $this->blobId;
        }
        if ($this->mediaId !== null) {
            $ret['media_id'] = $this->mediaId;
        }
        $ret['mimetype'] = $this->mimetype;

        return $ret;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        // Verify this is a valid type
        BlobType::from(self::str(self::req($raw, 'type')));
        $this->blobId = self::strOrNull(self::opt($raw, 'blob_id'));
        $this->mediaId = self::strOrNull(self::opt($raw, 'media_id'));
        $this->mimetype = self::strOrNull(self::opt($raw, 'mimetype'));
    }
}
