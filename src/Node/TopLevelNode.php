<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Top level node base class.
 *
 * Port of gkeepapi/node.py `TopLevelNode` (MIT, (c) Kai Zhong / kiwiz).
 */
class TopLevelNode extends Node
{
    /** Labels container. */
    public NodeLabels $labels;

    /** Collaborators container. */
    public NodeCollaborators $collaborators;

    private ColorValue $color = ColorValue::White;
    private bool $archived = false;
    private bool $pinned = false;
    private string $title = '';

    public function __construct(?string $id = null, ?NodeType $type = null)
    {
        parent::__construct($id, $type, Root::ID);
        $this->labels = new NodeLabels();
        $this->collaborators = new NodeCollaborators();
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['color'] = $this->color->value;
        $ret['isArchived'] = $this->archived;
        $ret['isPinned'] = $this->pinned;
        $ret['title'] = $this->title;
        $labels = $this->labels->save($clean);
        [$collaborators, $requests] = $this->collaborators->save($clean);
        if ($labels !== []) {
            $ret['labelIds'] = $labels;
        }
        $ret['collaborators'] = $collaborators;
        if ($requests !== []) {
            $ret['shareRequests'] = $requests;
        }

        return $ret;
    }

    /** Get the node color. */
    public function getColor(): ColorValue
    {
        return $this->color;
    }

    /** Set the node color. */
    public function setColor(ColorValue $value): void
    {
        $this->color = $value;
        $this->touch(true);
    }

    /** Get the archive state. */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** Set the archive state. */
    public function setArchived(bool $value): void
    {
        $this->archived = $value;
        $this->touch();
    }

    /** Get the pin state. */
    public function isPinned(): bool
    {
        return $this->pinned;
    }

    /** Set the pin state. */
    public function setPinned(bool $value): void
    {
        $this->pinned = $value;
        $this->touch();
    }

    /** Get the title. */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** Set the title. */
    public function setTitle(string $value): void
    {
        $this->title = $value;
        $this->touch(true);
    }

    /** Get the url for this node. */
    public function getUrl(): string
    {
        return 'https://keep.google.com/u/0/#' . $this->type?->value . '/' . $this->id;
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        return parent::isDirty() || $this->labels->isDirty() || $this->collaborators->isDirty();
    }

    /**
     * Get all media blobs.
     *
     * @return list<Blob>
     */
    public function getBlobs(): array
    {
        return array_values(array_filter(
            $this->getChildren(),
            static fn (Node $node): bool => $node instanceof Blob,
        ));
    }

    /**
     * Get all image blobs.
     *
     * @return list<Blob>
     */
    public function getImages(): array
    {
        return $this->blobsOfType(NodeImage::class);
    }

    /**
     * Get all drawing blobs.
     *
     * @return list<Blob>
     */
    public function getDrawings(): array
    {
        return $this->blobsOfType(NodeDrawing::class);
    }

    /**
     * Get all audio blobs.
     *
     * @return list<Blob>
     */
    public function getAudio(): array
    {
        return $this->blobsOfType(NodeAudio::class);
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->color = array_key_exists('color', $raw)
            ? ColorValue::from(self::str($raw['color']))
            : ColorValue::White;
        $this->archived = (bool) self::opt($raw, 'isArchived', false);
        $this->pinned = (bool) self::opt($raw, 'isPinned', false);
        $this->title = self::str(self::opt($raw, 'title', ''));
        $this->labels->load(self::optArray($raw, 'labelIds'));
        $this->collaborators->load(self::optArray($raw, 'roleInfo'), self::optArray($raw, 'shareRequests'));
        $this->moved = array_key_exists('moved', $raw);
    }

    /**
     * @param class-string<NodeBlob> $class
     * @return list<Blob>
     */
    private function blobsOfType(string $class): array
    {
        return array_values(array_filter(
            $this->getBlobs(),
            static fn (Blob $blob): bool => $blob->blob instanceof $class,
        ));
    }
}
