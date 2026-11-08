<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents a context annotation, which may contain other annotations.
 *
 * Port of gkeepapi/node.py `Context` (MIT, (c) Kai Zhong / kiwiz).
 */
class Context extends Annotation
{
    /** @var array<array-key, Annotation> */
    protected array $entries = [];

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $context = [];
        foreach ($this->entries as $entry) {
            $context = array_merge($context, $entry->save($clean));
        }
        // An empty context must stay a JSON object (`{}`), not become a list (`[]`).
        $ret['context'] = $context === [] ? new \stdClass() : $context;

        return $ret;
    }

    /**
     * Get all sub annotations.
     *
     * @return list<Annotation>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        if (parent::isDirty()) {
            return true;
        }
        foreach ($this->entries as $annotation) {
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
        $this->entries = [];
        $context = self::opt($raw, 'context', []);
        if ($context instanceof \stdClass) {
            $context = (array) $context;
        }
        if (!is_array($context)) {
            throw new \ValueError('Expected array for key context');
        }
        foreach ($context as $key => $entry) {
            $annotation = NodeAnnotations::fromJson([$key => $entry]);
            if ($annotation instanceof Annotation) {
                $this->entries[$key] = $annotation;
            }
        }
    }
}
