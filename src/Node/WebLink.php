<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a link annotation on a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `WebLink` (MIT, (c) Kai Zhong / kiwiz).
 */
class WebLink extends Annotation
{
    private ?string $title = null;
    private string $url = '';
    private ?string $imageUrl = null;
    private string $provenanceUrl = '';
    private ?string $description = null;

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['webLink'] = [
            'title' => $this->title,
            'url' => $this->url,
            'imageUrl' => $this->imageUrl,
            'provenanceUrl' => $this->provenanceUrl,
            'description' => $this->description,
        ];

        return $ret;
    }

    /** Get the link title. */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /** Set the link title. */
    public function setTitle(?string $value): void
    {
        $this->title = $value;
        $this->dirty = true;
    }

    /** Get the link url. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** Set the link url. */
    public function setUrl(string $value): void
    {
        $this->url = $value;
        $this->dirty = true;
    }

    /** Get the link image url. */
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /** Set the link image url. */
    public function setImageUrl(?string $value): void
    {
        $this->imageUrl = $value;
        $this->dirty = true;
    }

    /** Get the provenance url. */
    public function getProvenanceUrl(): string
    {
        return $this->provenanceUrl;
    }

    /** Set the provenance url. */
    public function setProvenanceUrl(string $value): void
    {
        $this->provenanceUrl = $value;
        $this->dirty = true;
    }

    /** Get the link description. */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** Set the link description. */
    public function setDescription(?string $value): void
    {
        $this->description = $value;
        $this->dirty = true;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $link = self::reqArray($raw, 'webLink');
        $this->title = self::strOrNull(self::opt($link, 'title', $this->title));
        $this->url = self::str(self::req($link, 'url'));
        $this->imageUrl = self::strOrNull(self::opt($link, 'imageUrl', $this->imageUrl));
        $this->provenanceUrl = self::str(self::req($link, 'provenanceUrl'));
        $this->description = self::strOrNull(self::opt($link, 'description', $this->description));
    }
}
