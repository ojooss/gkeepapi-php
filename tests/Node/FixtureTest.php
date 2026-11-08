<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Node\Blob;
use Gkeepapi\Node\ListItem;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\NodeFactory;
use Gkeepapi\Node\Note;

/**
 * Loads the anonymised upstream recording (tests/data/keep-00.json).
 */
final class FixtureTest extends NodeTestCase
{
    /** @return list<array<string, mixed>> */
    private function rawNodes(): array
    {
        $json = file_get_contents(__DIR__ . '/../data/keep-00.json');
        self::assertIsString($json);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['nodes']);

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $data['nodes'];

        return $nodes;
    }

    public function testEveryRecordedNodeIsParsedToTheMatchingClass(): void
    {
        $classes = [
            'NOTE' => Note::class,
            'LIST' => ListNode::class,
            'LIST_ITEM' => ListItem::class,
            'BLOB' => Blob::class,
        ];
        $counts = [];

        foreach ($this->rawNodes() as $raw) {
            $node = NodeFactory::fromJson($raw);

            self::assertNotNull($node);
            self::assertInstanceOf($classes[self::string($raw['type'])], $node);
            self::assertSame($raw['id'], $node->id);
            self::assertSame($raw['parentId'], $node->parentId);
            self::assertSame($raw['serverId'], $node->serverId);
            $type = self::string($raw['type']);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        self::assertEquals(['LIST_ITEM' => 55, 'NOTE' => 28, 'BLOB' => 14, 'LIST' => 11], $counts);
    }

    public function testSavedNodesKeepTheRecordedCoreFieldsAndReloadIdentically(): void
    {
        foreach ($this->rawNodes() as $raw) {
            $node = NodeFactory::fromJson($raw);
            self::assertNotNull($node);

            $saved = $node->save();

            self::assertSame($raw['id'], $saved['id']);
            self::assertSame($raw['type'], $saved['type']);
            self::assertSame($raw['parentId'], $saved['parentId']);
            self::assertSame($raw['text'] ?? '', $saved['text']);
            self::assertSame(
                self::arr($raw['timestamps'])['created'],
                NodeTimestampsHelper::normalise(self::string(self::arr($saved['timestamps'])['created'])),
            );

            // Upstream quirk: save() writes collaborators as `collaborators`, but load() reads `roleInfo`,
            // so collaborators do not survive a save/load round trip.
            $reloaded = NodeFactory::fromJson($saved);
            self::assertNotNull($reloaded);
            // Likewise, loaded labels are unresolved placeholders whose `deleted` stamp is "now" on save.
            $reloadedSaved = $reloaded->save();
            foreach (['saved' => &$saved, 'reloadedSaved' => &$reloadedSaved] as &$data) {
                unset($data['collaborators'], $data['labelIds']);
            }
            unset($data);
            self::assertEquals($saved, $reloadedSaved);
        }
    }

    public function testRecordedTopLevelNodesExposeTheirMetadata(): void
    {
        $titles = [];
        foreach ($this->rawNodes() as $raw) {
            $node = NodeFactory::fromJson($raw);
            if ($node instanceof Note || $node instanceof ListNode) {
                self::assertSame($raw['title'] ?? '', $node->getTitle());
                self::assertSame($raw['isPinned'] ?? false, $node->isPinned());
                self::assertSame($raw['isArchived'] ?? false, $node->isArchived());
                $titles[] = $node->getTitle();
            }
        }

        self::assertCount(39, $titles);
    }
}
