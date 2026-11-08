<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a drawing blob.
 *
 * Port of gkeepapi/node.py `NodeDrawing` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeDrawing extends NodeBlob
{
    private ?string $extractedText = '';
    private ?string $extractionStatus = '';
    private ?NodeDrawingInfo $drawingInfo = null;

    public function __construct()
    {
        parent::__construct(BlobType::Drawing);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['extracted_text'] = $this->extractedText;
        $ret['extraction_status'] = $this->extractionStatus;
        if ($this->drawingInfo instanceof NodeDrawingInfo) {
            $ret['drawingInfo'] = $this->drawingInfo->save($clean);
        }

        return $ret;
    }

    /** Get the drawing info, if loaded. */
    public function getDrawingInfo(): ?NodeDrawingInfo
    {
        return $this->drawingInfo;
    }

    /** Get text extracted from the drawing snapshot. */
    public function getExtractedText(): ?string
    {
        return $this->drawingInfo?->snapshot->getExtractedText() ?? '';
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->extractedText = self::strOrNull(self::opt($raw, 'extracted_text'));
        $this->extractionStatus = self::strOrNull(self::opt($raw, 'extraction_status'));
        $drawingInfo = null;
        if (array_key_exists('drawingInfo', $raw)) {
            $drawingInfo = new NodeDrawingInfo();
            $drawingInfo->load(self::reqArray($raw, 'drawingInfo'));
        }
        $this->drawingInfo = $drawingInfo;
    }
}
