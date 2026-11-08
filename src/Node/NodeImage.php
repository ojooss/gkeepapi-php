<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents an image blob.
 *
 * Port of gkeepapi/node.py `NodeImage` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeImage extends NodeBlob
{
    private bool $isUploaded = false;
    private ?int $width = 0;
    private ?int $height = 0;
    private ?int $byteSize = 0;
    private ?string $extractedText = '';
    private ?string $extractionStatus = '';

    public function __construct()
    {
        parent::__construct(BlobType::Image);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['width'] = $this->width;
        $ret['height'] = $this->height;
        $ret['byte_size'] = $this->byteSize;
        $ret['extracted_text'] = $this->extractedText;
        $ret['extraction_status'] = $this->extractionStatus;

        return $ret;
    }

    /** Get whether the image has been uploaded. */
    public function isUploaded(): bool
    {
        return $this->isUploaded;
    }

    /** Get width of image. */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /** Get height of image. */
    public function getHeight(): ?int
    {
        return $this->height;
    }

    /** Get size of image in bytes. */
    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    /** Get text extracted from image. */
    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    /**
     * Get a url to the image.
     *
     * @throws \BadMethodCallException Not implemented upstream either.
     */
    public function getUrl(): string
    {
        throw new \BadMethodCallException('Not implemented');
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->isUploaded = (bool) self::opt($raw, 'is_uploaded', false);
        $this->width = self::intOrNull(self::opt($raw, 'width'));
        $this->height = self::intOrNull(self::opt($raw, 'height'));
        $this->byteSize = self::intOrNull(self::opt($raw, 'byte_size'));
        $this->extractedText = self::strOrNull(self::opt($raw, 'extracted_text'));
        $this->extractionStatus = self::strOrNull(self::opt($raw, 'extraction_status'));
    }
}
