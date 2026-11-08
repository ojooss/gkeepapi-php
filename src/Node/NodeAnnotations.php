<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Log;

/**
 * Represents the annotation container on a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `NodeAnnotations` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeAnnotations extends Element implements \Countable
{
    /** @var array<string, Annotation> */
    protected array $annotations = [];

    /**
     * Helper to construct an annotation from a dict.
     *
     * @param array<array-key, mixed> $raw Raw annotation representation.
     * @return Annotation|null An Annotation object or null for unknown types.
     */
    public static function fromJson(array $raw): ?Annotation
    {
        $annotation = match (true) {
            array_key_exists('webLink', $raw) => new WebLink(),
            array_key_exists('topicCategory', $raw) => new Category(),
            array_key_exists('taskAssist', $raw) => new TaskAssist(),
            array_key_exists('context', $raw) => new Context(),
            default => null,
        };

        if ($annotation === null) {
            Log::logger()->warning('Unknown annotation type', ['keys' => array_keys($raw)]);

            return null;
        }
        $annotation->load($raw);

        return $annotation;
    }

    public function count(): int
    {
        return count($this->annotations);
    }

    /**
     * Get all annotations.
     *
     * @return list<Annotation>
     */
    public function all(): array
    {
        return array_values($this->annotations);
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['kind'] = 'notes#annotationsGroup';
        if ($this->annotations !== []) {
            $ret['annotations'] = array_map(
                static fn (Annotation $annotation): array => $annotation->save($clean),
                array_values($this->annotations),
            );
        }

        return $ret;
    }

    /** Get the category. */
    public function getCategory(): ?CategoryValue
    {
        return $this->getCategoryNode()?->getCategory();
    }

    /** Set the category; null removes it. */
    public function setCategory(?CategoryValue $value): void
    {
        $node = $this->getCategoryNode();
        if (!$value instanceof CategoryValue) {
            if ($node instanceof Category && $node->id !== null) {
                unset($this->annotations[$node->id]);
            }
        } else {
            if (!$node instanceof Category) {
                $node = new Category();
                $this->annotations[(string) $node->id] = $node;
            }
            $node->setCategory($value);
        }
        $this->dirty = true;
    }

    /**
     * Get all links.
     *
     * @return list<WebLink>
     */
    public function getLinks(): array
    {
        return array_values(array_filter(
            $this->annotations,
            static fn (Annotation $annotation): bool => $annotation instanceof WebLink,
        ));
    }

    /** Add an annotation. */
    public function append(Annotation $annotation): Annotation
    {
        $this->annotations[(string) $annotation->id] = $annotation;
        $this->dirty = true;

        return $annotation;
    }

    /** Removes an annotation. */
    public function remove(Annotation $annotation): void
    {
        unset($this->annotations[(string) $annotation->id]);
        $this->dirty = true;
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        if (parent::isDirty()) {
            return true;
        }
        foreach ($this->annotations as $annotation) {
            if ($annotation->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->annotations = [];
        if (!array_key_exists('annotations', $raw)) {
            return;
        }

        foreach (self::optArray($raw, 'annotations') as $rawAnnotation) {
            if (!is_array($rawAnnotation)) {
                throw new \ValueError('Expected annotation object');
            }
            $annotation = self::fromJson($rawAnnotation);
            if ($annotation instanceof Annotation) {
                $this->annotations[(string) $annotation->id] = $annotation;
            }
        }
    }

    private function getCategoryNode(): ?Category
    {
        foreach ($this->annotations as $annotation) {
            if ($annotation instanceof Category) {
                return $annotation;
            }
        }

        return null;
    }
}
