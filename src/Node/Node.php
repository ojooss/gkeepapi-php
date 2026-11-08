<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Exception\MergeException;
use Gkeepapi\Log;

/**
 * Node base class.
 *
 * Port of gkeepapi/node.py `Node` (MIT, (c) Kai Zhong / kiwiz).
 */
class Node extends Element
{
    use TimestampsMixin;

    /** Parent node, if attached. */
    public ?Node $parent = null;

    /** Node ID. */
    public string $id;

    /** Server-side node ID, null while the node is new. */
    public ?string $serverId = null;

    /** Settings container. */
    public NodeSettings $settings;

    /** Annotations container. */
    public NodeAnnotations $annotations;

    protected int|string $sort;
    protected int|string|null $version = null;
    protected string $text = '';

    /** @var array<string, Node> */
    protected array $children = [];

    /** Set if there is no baseVersion in the raw data. */
    protected bool $moved = false;

    public function __construct(
        ?string $id = null,
        /** Node type. */
        public ?NodeType $type = null,
        /** Parent node ID. */
        public ?string $parentId = null,
    ) {
        $createTime = microtime(true);

        $this->id = $id ?? $this->generateId($createTime);
        $this->sort = random_int(1000000000, 9999999999);
        $this->timestamps = new NodeTimestamps($createTime);
        $this->settings = new NodeSettings();
        $this->annotations = new NodeAnnotations();
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['id'] = $this->id;
        $ret['kind'] = 'notes#node';
        $ret['type'] = $this->type?->value;
        $ret['parentId'] = $this->parentId;
        $ret['sortValue'] = $this->sort;
        if (!$this->moved && $this->version !== null) {
            $ret['baseVersion'] = $this->version;
        }
        $ret['text'] = $this->text;
        if ($this->serverId !== null) {
            $ret['serverId'] = $this->serverId;
        }
        $ret['timestamps'] = $this->timestamps->save($clean);
        $ret['nodeSettings'] = $this->settings->save($clean);
        $ret['annotationsGroup'] = $this->annotations->save($clean);

        return $ret;
    }

    /** Get the sort id. */
    public function getSort(): int
    {
        return (int) $this->sort;
    }

    /** Set the sort id. */
    public function setSort(int $value): void
    {
        $this->sort = $value;
        $this->touch();
    }

    /** Get the node version. */
    public function getVersion(): int|string|null
    {
        return $this->version;
    }

    /** Get the text value. */
    public function getText(): string
    {
        return $this->text;
    }

    /** Set the text value. */
    public function setText(string $value): void
    {
        $this->text = $value;
        $this->timestamps->setEdited(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->touch(true);
    }

    /**
     * Get all children.
     *
     * @return list<Node>
     */
    public function getChildren(): array
    {
        return array_values($this->children);
    }

    /** Get child node with the given ID. */
    public function get(string $nodeId): ?Node
    {
        return $this->children[$nodeId] ?? null;
    }

    /**
     * Add a new child node.
     *
     * @param bool $dirty Whether this node should be marked dirty.
     */
    public function append(Node $node, bool $dirty = true): Node
    {
        $this->children[$node->id] = $node;
        $node->parent = $this;
        if ($dirty) {
            $this->touch();
        }

        return $node;
    }

    /**
     * Remove the given child node.
     *
     * @param bool $dirty Whether this node should be marked dirty.
     */
    public function remove(Node $node, bool $dirty = true): void
    {
        if (array_key_exists($node->id, $this->children)) {
            $this->children[$node->id]->parent = null;
            unset($this->children[$node->id]);
        }
        if ($dirty) {
            $this->touch();
        }
    }

    /** Get whether this node is new, i.e. has not been persisted to the server. */
    public function isNew(): bool
    {
        return $this->serverId === null;
    }

    /** @inheritDoc */
    public function isDirty(): bool
    {
        if (
            parent::isDirty()
            || $this->timestamps->isDirty()
            || $this->annotations->isDirty()
            || $this->settings->isDirty()
        ) {
            return true;
        }
        foreach ($this->children as $node) {
            if ($node->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        // Verify this is a valid type
        NodeType::from(self::str(self::req($raw, 'type')));
        $kind = self::req($raw, 'kind');
        if ($kind !== 'notes#node') {
            Log::logger()->warning('Unknown node kind', ['kind' => is_scalar($kind) ? (string) $kind : '']);
        }
        if (array_key_exists('mergeConflict', $raw)) {
            throw new MergeException('Merge conflict in node ' . self::str(self::opt($raw, 'id', '')));
        }

        $this->id = self::str(self::req($raw, 'id'));
        $this->serverId = self::strOrNull(self::opt($raw, 'serverId', $this->serverId));
        $this->parentId = self::strOrNull(self::req($raw, 'parentId'));
        $sort = self::opt($raw, 'sortValue', $this->getSort());
        $this->sort = is_int($sort) ? $sort : self::str($sort);
        $version = self::opt($raw, 'baseVersion', $this->version);
        $this->version = is_int($version) ? $version : self::strOrNull($version);
        $this->text = self::str(self::opt($raw, 'text', $this->text));
        $this->timestamps->load(self::reqArray($raw, 'timestamps'));
        $this->settings->load(self::reqArray($raw, 'nodeSettings'));
        $this->annotations->load(self::reqArray($raw, 'annotationsGroup'));
    }

    private function generateId(float $tz): string
    {
        return sprintf('%x.%s', (int) ($tz * 1000), bin2hex(random_bytes(8)));
    }
}
