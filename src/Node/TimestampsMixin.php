<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A mixin to add methods for updating timestamps.
 *
 * Port of gkeepapi/node.py `TimestampsMixin` (MIT, (c) Kai Zhong / kiwiz).
 *
 * @phpstan-require-extends Element
 */
trait TimestampsMixin
{
    /** Timestamps of the element. */
    public NodeTimestamps $timestamps;

    /**
     * Mark the node as dirty.
     *
     * @param bool $edited Whether to set the edited time.
     */
    public function touch(bool $edited = false): void
    {
        $this->dirty = true;
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->timestamps->setUpdated($dt);
        if ($edited) {
            $this->timestamps->setEdited($dt);
        }
    }

    /** Get the trashed state. */
    public function isTrashed(): bool
    {
        $trashed = $this->timestamps->getTrashed();

        return $trashed !== null && $trashed > NodeTimestamps::intToDt(0);
    }

    /** Mark the item as trashed. */
    public function trash(): void
    {
        $this->timestamps->setTrashed(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** Mark the item as untrashed. */
    public function untrash(): void
    {
        $this->timestamps->setTrashed(NodeTimestamps::intToDt(0));
    }

    /** Get the deleted state. */
    public function isDeleted(): bool
    {
        $deleted = $this->timestamps->getDeleted();

        return $deleted !== null && $deleted > NodeTimestamps::intToDt(0);
    }

    /** Mark the item as deleted. */
    public function delete(): void
    {
        $this->timestamps->setDeleted(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** Mark the item as undeleted. */
    public function undelete(): void
    {
        $this->timestamps->setDeleted(null);
    }
}
