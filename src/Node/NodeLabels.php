<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Represents the labels on a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `NodeLabels` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeLabels extends Element implements \Countable
{
    /** @var array<string, Label|null> Label ID => label; null marks a removed label. */
    private array $labels = [];

    public function count(): int
    {
        return count($this->labels);
    }

    /**
     * Serialize into the raw label list; with `$clean` false a trailing dirty bool is appended.
     *
     * @return list<array<string, string>|bool>
     */
    public function save(bool $clean = true): array
    {
        $ret = [];
        foreach ($this->labels as $labelId => $label) {
            $ret[] = [
                'labelId' => (string) $labelId,
                'deleted' => $label === null
                    ? NodeTimestamps::dtToStr(new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    : NodeTimestamps::intToStr(0),
            ];
        }
        if (!$clean) {
            $ret[] = $this->dirty;
        } else {
            $this->dirty = false;
        }

        return $ret;
    }

    /** Add a label. */
    public function add(Label $label): void
    {
        $this->labels[$label->id] = $label;
        $this->dirty = true;
    }

    /** Remove a label. */
    public function remove(Label $label): void
    {
        if (array_key_exists($label->id, $this->labels)) {
            $this->labels[$label->id] = null;
        }
        $this->dirty = true;
    }

    /** Get a label by ID. */
    public function get(string $labelId): ?Label
    {
        return $this->labels[$labelId] ?? null;
    }

    /**
     * Get all labels.
     *
     * @return list<Label>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->labels,
            static fn (?Label $label): bool => $label instanceof Label,
        ));
    }

    /**
     * Resolve the label references against the known labels (unknown IDs resolve to null).
     *
     * @internal Used by {@see \Gkeepapi\Keep} after syncing.
     * @param array<string, Label> $labels Label ID => label.
     */
    public function resolve(array $labels): void
    {
        foreach (array_keys($this->labels) as $labelId) {
            $this->labels[$labelId] = $labels[$labelId] ?? null;
        }
    }

    /**
     * Unserialize from the raw label list (a trailing bool holds the dirty state).
     * The parent implementation is deliberately not used.
     *
     * @param array<array-key, mixed> $raw
     */
    protected function doLoad(array $raw): void
    {
        $raw = array_values($raw);
        $last = end($raw);
        if ($raw !== [] && is_bool($last)) {
            $this->dirty = $last;
            array_pop($raw);
        } else {
            $this->dirty = false;
        }

        $this->labels = [];
        foreach ($raw as $rawLabel) {
            if (!is_array($rawLabel)) {
                throw new \ValueError('Expected label object');
            }
            $this->labels[self::str(self::req($rawLabel, 'labelId'))] = null;
        }
    }
}
