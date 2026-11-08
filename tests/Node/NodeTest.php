<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Exception\MergeException;
use Gkeepapi\Exception\ParseException;
use Gkeepapi\Node\Blob;
use Gkeepapi\Node\ColorValue;
use Gkeepapi\Node\Label;
use Gkeepapi\Node\ListItem;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\NewListItemPlacementValue;
use Gkeepapi\Node\Node;
use Gkeepapi\Node\NodeFactory;
use Gkeepapi\Node\NodeTimestamps;
use Gkeepapi\Node\NodeType;
use Gkeepapi\Node\Note;
use Gkeepapi\Node\Root;
use Gkeepapi\Node\TopLevelNode;

final class NodeTest extends NodeTestCase
{
    public function testSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): Node => new Node(type: NodeType::Note));
        self::assertSaveLoadRoundTrips(static fn (): TopLevelNode => new TopLevelNode(type: NodeType::Note));
        self::assertSaveLoadRoundTrips(static fn (): Note => new Note());
        self::assertSaveLoadRoundTrips(static fn (): ListNode => new ListNode());
        self::assertSaveLoadRoundTrips(static fn (): ListItem => new ListItem());
    }

    public function testFields(): void
    {
        $n = new Node(type: NodeType::Note);
        $tz = NodeTimestamps::intToDt(0);
        self::cleanNode($n);

        $n->timestamps->setCreated($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->timestamps->getCreated());

        self::cleanNode($n);
        $n->setSort(1);
        self::assertTrue($n->isDirty());
        self::assertSame(1, $n->getSort());

        self::cleanNode($n);
        $n->setText('Text');
        self::assertTrue($n->isDirty());
        self::assertSame('Text', $n->getText());

        self::cleanNode($n);
        $n->settings->setNewListitemPlacement(NewListItemPlacementValue::Bottom);
        self::assertTrue($n->isDirty());

        self::cleanNode($n);
        $n->annotations->setCategory(null);
        self::assertTrue($n->isDirty());
        self::assertNull($n->annotations->getCategory());

        $sub = new ListItem();
        self::cleanNode($sub);
        self::cleanNode($n);
        $n->append($sub);
        self::assertTrue($n->isDirty());
        self::assertSame($n, $sub->parent);
        self::assertSame($sub, $n->get($sub->id));

        self::cleanNode($n);
        $sub->setText('Text');
        self::assertTrue($n->isDirty());

        self::cleanNode($n);
        $sub->delete();
        self::assertTrue($n->isDirty());
    }

    public function testDelete(): void
    {
        $n = new Node(type: NodeType::Note);
        self::cleanNode($n);

        $n->delete();

        self::assertNotNull($n->timestamps->getDeleted());
        self::assertTrue($n->isDirty());
    }

    public function testRemoveDetachesChild(): void
    {
        $n = new Node(type: NodeType::Note);
        $sub = new ListItem();
        $n->append($sub);

        $n->remove($sub);

        self::assertNull($sub->parent);
        self::assertSame([], $n->getChildren());
    }

    public function testNewUntilServerIdIsKnown(): void
    {
        $n = new Node(type: NodeType::Note);
        self::assertTrue($n->isNew());

        $n->serverId = 'abc';
        self::assertFalse($n->isNew());
    }

    public function testGeneratedIdFormat(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]+\.[0-9a-f]{16}$/', (new Node())->id);
    }

    public function testRootIsNeverDirty(): void
    {
        $r = new Root();

        self::assertSame('root', $r->id);
        self::assertFalse($r->isDirty());
    }

    public function testLoadWithoutDataFailsWithParseException(): void
    {
        $this->expectException(ParseException::class);

        (new Node())->load([]);
    }

    public function testMergeConflictRaisesMergeException(): void
    {
        $this->expectException(MergeException::class);

        (new Node())->load($this->minimalNoteData() + ['mergeConflict' => []]);
    }

    public function testBaseVersionIsOmittedForMovedNodes(): void
    {
        $note = new Note();
        $note->load($this->minimalNoteData() + ['baseVersion' => '7']);
        self::assertSame('7', $note->getVersion());
        self::assertSame('7', $note->save()['baseVersion']);

        $moved = new Note();
        $moved->load($this->minimalNoteData() + ['baseVersion' => '7', 'moved' => '1']);
        self::assertArrayNotHasKey('baseVersion', $moved->save());
    }

    public function testTopLevelNodeFields(): void
    {
        $n = new TopLevelNode(type: NodeType::Note);
        self::cleanNode($n);

        $n->setColor(ColorValue::Red);
        self::assertTrue($n->isDirty());
        self::assertSame(ColorValue::Red, $n->getColor());

        self::cleanNode($n);
        $n->setArchived(true);
        self::assertTrue($n->isDirty());
        self::assertTrue($n->isArchived());

        self::cleanNode($n);
        $n->setPinned(true);
        self::assertTrue($n->isDirty());
        self::assertTrue($n->isPinned());

        self::cleanNode($n);
        $n->setTitle('Title');
        self::assertTrue($n->isDirty());
        self::assertSame('Title', $n->getTitle());

        $label = new Label();
        $label->setName('x');
        self::cleanNode($label);
        self::cleanNode($n);
        $n->labels->add($label);
        self::assertTrue($n->isDirty());

        $blob = new Blob();
        self::cleanNode($blob);
        self::cleanNode($n);
        $n->append($blob);
        self::assertSame([$blob], $n->getBlobs());

        self::cleanNode($n);
        $n->labels->remove($label);
        self::assertTrue($n->isDirty());
    }

    public function testTopLevelNodeSaveContainsLabelsAndShareRequestsOnlyWhenPresent(): void
    {
        $n = new TopLevelNode(type: NodeType::Note);
        $saved = $n->save();
        self::assertArrayNotHasKey('labelIds', $saved);
        self::assertArrayNotHasKey('shareRequests', $saved);
        self::assertSame([], $saved['collaborators']);

        $label = new Label();
        $n->labels->add($label);
        $n->collaborators->add('user@example.com');
        $saved = $n->save();

        self::assertSame($label->id, self::arr(self::arr($saved['labelIds'])[0])['labelId']);
        self::assertSame([['email' => 'user@example.com', 'type' => 'WR']], $saved['shareRequests']);
    }

    public function testCollaboratorsMakeNodeDirty(): void
    {
        $n = new TopLevelNode(type: NodeType::Note);
        self::cleanNode($n);

        $n->collaborators->add('user@example.com');
        self::assertTrue($n->isDirty());
        self::assertCount(1, $n->collaborators);

        self::cleanNode($n);
        $n->collaborators->remove('user@example.com');
        self::assertTrue($n->isDirty());
    }

    public function testNoteFields(): void
    {
        $n = new Note('3');
        self::cleanNode($n);

        $n->setText('Text');

        self::assertTrue($n->isDirty());
        self::assertSame('Text', $n->getText());
        self::assertSame('https://keep.google.com/u/0/#NOTE/3', $n->getUrl());
    }

    public function testNoteToString(): void
    {
        $n = new Note();
        self::assertSame('', $n->getText());

        $n->setTitle('Title');
        $n->setText('Test');

        self::assertSame("Title\nTest", (string) $n);
        self::assertSame('Test', $n->getText());
    }

    public function testNoteStoresTextInSingleChildListItem(): void
    {
        $n = new Note('n1');

        $n->setText('first');
        $n->setText('second');

        self::assertCount(1, $n->getChildren());
        self::assertSame('n1', $n->getChildren()[0]->parentId);
        self::assertSame('second', $n->getText());
    }

    public function testListFields(): void
    {
        $n = new ListNode();
        self::cleanNode($n);

        $a = $n->add('Text', sort: 1);
        $b = $n->add('Text', true, sort: 2);

        self::assertTrue($n->isDirty());
        self::assertSame([$b], $n->getChecked());
        self::assertSame([$a], $n->getUnchecked());
        self::assertSame('https://keep.google.com/u/0/#LIST/' . $n->id, $n->getUrl());
    }

    public function testListIgnoresDeletedItems(): void
    {
        $n = new ListNode();
        $a = $n->add('a', sort: 1);
        $b = $n->add('b', sort: 2);

        $b->delete();

        self::assertSame([$a], $n->getItems());
    }

    public function testListIndent(): void
    {
        $n = new ListNode();
        self::cleanNode($n);
        $a = new ListItem();
        self::cleanNode($a);
        $b = $n->add('Test');
        self::cleanNode($b);

        try {
            $a->add('x');
            self::fail('Expected InvalidException');
        } catch (InvalidException) {
            // an item without a parent list cannot get subitems
        }

        $c = $b->add('Test');
        self::assertInstanceOf(ListItem::class, $c);
        self::cleanNode($b);

        $a->indent($b);
        self::assertFalse($b->isDirty());

        self::cleanNode($b);
        $a->dedent($b);
        self::assertFalse($b->isDirty());

        self::cleanNode($c);
        $b->dedent($c);
        self::assertTrue($c->isDirty());
        self::assertFalse($c->isIndented());
        self::assertSame('', $c->superListItemId);
    }

    public function testListSortItems(): void
    {
        $n = new ListNode();
        $a = $n->add('a', sort: 3);
        $b = $n->add('b', sort: 0);
        $c = $n->add('c', sort: 5);
        $d = $n->add('d', sort: 1);
        $e = $n->add('e', sort: 2);
        $f = $n->add('f', sort: 4);

        $n->sortItems();

        self::assertSame(
            [$a->id, $b->id, $c->id, $d->id, $e->id, $f->id],
            array_map(static fn (ListItem $i): string => $i->id, $n->getItems()),
        );
    }

    public function testListSortItemsKeepsSubitemsBelowParent(): void
    {
        $n = new ListNode();
        $a = $n->add('a', sort: 3);
        $ba = $a->add('ba', sort: 3);
        $aa = $a->add('aa', sort: 2);
        $b = $n->add('b', sort: 4);
        $bd = $b->add('bd', sort: 4);
        $bc = $b->add('bc', sort: 3);

        $n->sortItems();

        self::assertSame(
            [$a->id, $aa->id, $ba->id, $b->id, $bc->id, $bd->id],
            array_map(static fn (ListItem $i): string => $i->id, $n->getItems()),
        );
    }

    public function testListSortItemsWithCustomKey(): void
    {
        $n = new ListNode();
        $one = $n->add('test1');
        $two = $n->add('test2');
        $three = $n->add('test3');
        $one->timestamps->setCreated(NodeTimestamps::intToDt(1));
        $two->timestamps->setCreated(NodeTimestamps::intToDt(2));
        $three->timestamps->setCreated(NodeTimestamps::intToDt(3));

        $n->sortItems(static fn (ListItem $i): \DateTimeImmutable => $i->timestamps->getCreated(), true);

        self::assertSame(
            [$three->id, $two->id, $one->id],
            array_map(static fn (ListItem $i): string => $i->id, $n->getItems()),
        );
    }

    public function testListToString(): void
    {
        $n = new ListNode();
        $n->setTitle('Title');
        $n->add('a', sort: 0);
        $n->add('b', sort: 0);
        $c = $n->add('c', sort: 1);
        $d = $n->add('d', sort: 2);
        $e = $n->add('e', sort: 3);
        $f = $n->add('f', sort: 4);
        $n->add('g', sort: NewListItemPlacementValue::Bottom);
        $c->indent($d);
        $f->indent($e);

        $text = "☐ f\n  ☐ e\n☐ c\n  ☐ d\n☐ a\n☐ b\n☐ g";

        self::assertSame("Title\n" . $text, (string) $n);
        self::assertSame($text, $n->getText());
    }

    public function testListAddWithPlacementPolicy(): void
    {
        $n = new ListNode();
        $n->add('mid', sort: 100000);

        $top = $n->add('top', sort: NewListItemPlacementValue::Top);
        $bottom = $n->add('bottom', sort: NewListItemPlacementValue::Bottom);

        self::assertSame(110000, $top->getSort());
        self::assertSame(100000 - ListNode::SORT_DELTA, $bottom->getSort());
    }

    public function testListItemFields(): void
    {
        $n = new ListItem();
        self::cleanNode($n);

        $n->setText('Text');
        self::assertTrue($n->isDirty());
        self::assertSame('Text', $n->getText());

        self::cleanNode($n);
        $n->setChecked(true);
        self::assertTrue($n->isDirty());
        self::assertTrue($n->isChecked());
        self::assertSame('☑ Text', (string) $n);
    }

    public function testListItemLoadTreatsEmptySuperListItemIdAsNull(): void
    {
        $item = new ListItem();
        $item->load($this->minimalNoteData(['type' => 'LIST_ITEM', 'superListItemId' => '']));

        self::assertNull($item->superListItemId);
    }

    public function testFactoryReturnsNullForUnknownTypes(): void
    {
        self::assertNull(NodeFactory::fromJson([]));
        self::assertNull(NodeFactory::fromJson(['type' => 'HOLOGRAM']));
    }

    public function testFactoryBuildsNodesByType(): void
    {
        self::assertInstanceOf(Note::class, NodeFactory::fromJson($this->minimalNoteData()));
        self::assertInstanceOf(ListNode::class, NodeFactory::fromJson($this->minimalNoteData(['type' => 'LIST'])));
        self::assertInstanceOf(ListItem::class, NodeFactory::fromJson($this->minimalNoteData(['type' => 'LIST_ITEM'])));
        self::assertInstanceOf(Blob::class, NodeFactory::fromJson($this->minimalNoteData(['type' => 'BLOB'])));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimalNoteData(array $overrides = []): array
    {
        return $overrides + [
            'id' => '',
            'parentId' => '',
            'timestamps' => [
                'created' => '2000-01-01T00:00:00.000Z',
                'updated' => '2000-01-01T00:00:00.000Z',
            ],
            'nodeSettings' => [
                'newListItemPlacement' => 'TOP',
                'graveyardState' => 'COLLAPSED',
                'checkedListItemsPolicy' => 'DEFAULT',
            ],
            'annotationsGroup' => [],
            'kind' => 'notes#node',
            'type' => 'NOTE',
        ];
    }
}
