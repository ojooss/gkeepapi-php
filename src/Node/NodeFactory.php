<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Exception\ParseException;
use Gkeepapi\Log;

/**
 * Constructs nodes from their raw representation.
 *
 * Port of gkeepapi/node.py module-level `from_json` (MIT, (c) Kai Zhong / kiwiz).
 */
final class NodeFactory
{
    private function __construct()
    {
    }

    /**
     * Helper to construct a node from a dict.
     *
     * @param array<array-key, mixed> $raw Raw node representation.
     * @return Node|null A Node object or null for missing/unknown types.
     * @throws ParseException If the node cannot be parsed.
     */
    public static function fromJson(array $raw): ?Node
    {
        $type = $raw['type'] ?? null;
        $nodeType = is_string($type) ? NodeType::tryFrom($type) : null;
        $node = match ($nodeType) {
            NodeType::Note => new Note(),
            NodeType::List => new ListNode(),
            NodeType::ListItem => new ListItem(),
            NodeType::Blob => new Blob(),
            null => null,
        };
        if ($node === null) {
            Log::logger()->warning('Unknown node type', ['type' => is_scalar($type) ? (string) $type : '']);

            return null;
        }
        $node->load($raw);

        return $node;
    }
}
