<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use DateTimeImmutable;

/**
 * Represents information about a drawing blob.
 *
 * Port of gkeepapi/node.py `NodeDrawingInfo` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeDrawingInfo extends Element
{
    /** Drawing ID. */
    public string $drawingId = '';

    /** Snapshot image of the drawing. */
    public NodeImage $snapshot;

    private string $snapshotFingerprint = '';
    private DateTimeImmutable $thumbnailGeneratedTime;
    private string $inkHash = '';
    private string $snapshotProtoFprint = '';

    public function __construct()
    {
        $this->snapshot = new NodeImage();
        $this->thumbnailGeneratedTime = NodeTimestamps::intToDt(0);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['drawingId'] = $this->drawingId;
        $ret['snapshotData'] = $this->snapshot->save($clean);
        $ret['snapshotFingerprint'] = $this->snapshotFingerprint;
        $ret['thumbnailGeneratedTime'] = NodeTimestamps::dtToStr($this->thumbnailGeneratedTime);
        $ret['inkHash'] = $this->inkHash;
        $ret['snapshotProtoFprint'] = $this->snapshotProtoFprint;

        return $ret;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->drawingId = self::str(self::req($raw, 'drawingId'));
        $this->snapshot->load(self::reqArray($raw, 'snapshotData'));
        $this->snapshotFingerprint = self::str(self::opt($raw, 'snapshotFingerprint', $this->snapshotFingerprint));
        $this->thumbnailGeneratedTime = NodeTimestamps::strToDt(
            self::strOrNull(self::opt($raw, 'thumbnailGeneratedTime')),
        );
        $this->inkHash = self::str(self::opt($raw, 'inkHash', ''));
        $this->snapshotProtoFprint = self::str(self::opt($raw, 'snapshotProtoFprint', $this->snapshotProtoFprint));
    }
}
