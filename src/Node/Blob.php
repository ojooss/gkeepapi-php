<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Log;

/**
 * Represents a Google Keep blob.
 *
 * Port of gkeepapi/node.py `Blob` (MIT, (c) Kai Zhong / kiwiz).
 */
class Blob extends Node
{
    /** The blob descriptor. */
    public ?NodeBlob $blob = null;

    public function __construct(?string $parentId = null, ?string $id = null)
    {
        parent::__construct($id, NodeType::Blob, $parentId);
    }

    /**
     * Helper to construct a blob from a dict.
     *
     * @param array<array-key, mixed>|null $raw Raw blob representation.
     * @return NodeBlob|null A NodeBlob object or null for missing/unknown types.
     */
    public static function fromJson(?array $raw): ?NodeBlob
    {
        if ($raw === null) {
            return null;
        }
        $type = $raw['type'] ?? null;
        if ($type === null) {
            return null;
        }

        $blobType = is_string($type) ? BlobType::tryFrom($type) : null;
        $blob = match ($blobType) {
            BlobType::Audio => new NodeAudio(),
            BlobType::Image => new NodeImage(),
            BlobType::Drawing => new NodeDrawing(),
            null => null,
        };
        if ($blob === null) {
            Log::logger()->warning('Unknown blob type', ['type' => is_scalar($type) ? (string) $type : '']);

            return null;
        }
        $blob->load($raw);

        return $blob;
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        if ($this->blob instanceof NodeBlob) {
            $ret['blob'] = $this->blob->save($clean);
        }

        return $ret;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $blob = self::opt($raw, 'blob');
        $this->blob = self::fromJson(is_array($blob) ? $blob : null);
    }
}
