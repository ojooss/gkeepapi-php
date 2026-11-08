<?php

declare(strict_types=1);

namespace Gkeepapi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Gkeepapi\Tests\Http\RecordingHttpClient;
use Gkeepapi\Node\TopLevelNode;
use Gkeepapi\Exception\BrowserLoginRequiredException;
use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Exception\LabelException;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Exception\ParseException;
use Gkeepapi\Exception\ResyncRequiredException;
use Gkeepapi\Exception\SyncException;
use Gkeepapi\Exception\UpgradeRecommendedException;
use Gkeepapi\Keep;
use Gkeepapi\Node\Blob;
use Gkeepapi\Node\ColorValue;
use Gkeepapi\Node\ListItem;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\Node;
use Gkeepapi\Node\Note;
use Gkeepapi\Pattern;

final class KeepTest extends KeepTestCase
{
    /** @return array<array-key, mixed> */
    private function fixture(): array
    {
        $json = file_get_contents(__DIR__ . '/data/keep-00.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    // --- Authentication ---------------------------------------------------------------------------------------

    public function testAuthenticateSyncsTheRecordedAccount(): void
    {
        $this->http->queueBody("Auth=tok-1\n")->queueBody(json_encode($this->fixture(), JSON_THROW_ON_ERROR));

        $this->keep->authenticate('user@example.com', 'master-token', null, true, 'dev123');

        self::assertCount(39, $this->keep->all());
        self::assertCount(2, $this->http->requests);
        self::assertSame('https://www.googleapis.com/notes/v1/changes', (string) $this->http->requests[1]->getUri());
        self::assertSame('OAuth tok-1', $this->http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('master-token', $this->keep->getMasterToken());
        self::assertCount(3, $this->keep->labels());
    }

    public function testLoginIsTheDeprecatedPasswordFlow(): void
    {
        $this->http->queueBody("Token=master-1\n")->queueBody("Auth=tok-1\n");
        $this->queueChanges(['nodes' => [self::rawNode('n1')]]);

        $this->keep->login('user@example.com', 'secret', null, true, 'dev123');

        self::assertSame('master-1', $this->keep->getMasterToken());
        self::assertCount(1, $this->keep->all());
        self::assertStringNotContainsString('secret', (string) $this->http->requests[1]->getBody());
    }

    public function testLoginPropagatesBrowserLoginRequired(): void
    {
        $this->http->queueBody("Error=NeedsBrowser\nUrl=https://accounts.example/x\n");

        $this->expectException(BrowserLoginRequiredException::class);

        $this->keep->login('user@example.com', 'secret', null, true, 'dev123');
    }

    public function testResumeIsAnAliasOfAuthenticate(): void
    {
        $this->http->queueBody("Auth=tok-1\n");

        $this->keep->resume('user@example.com', 'master-token', null, false, 'dev123');

        self::assertSame('master-token', $this->keep->getMasterToken());
    }

    public function testAuthenticateFailureIsALoginException(): void
    {
        $this->http->queueBody("Error=BadAuthentication\n", 403);

        $this->expectException(LoginException::class);

        $this->keep->authenticate('user@example.com', 'bad-token', null, true, 'dev123');
    }

    public function testSyncWithoutLoginFails(): void
    {
        $this->expectException(LoginException::class);

        $this->keep->sync();
    }

    public function testMasterTokenIsUnknownBeforeAuthentication(): void
    {
        self::assertNull($this->keep->getMasterToken());
    }

    // --- Sync -------------------------------------------------------------------------------------------------

    public function testSyncUploadsNewNotesAndAdoptsServerIds(): void
    {
        $this->login();
        $note = $this->keep->createNote('Title', 'Body');
        $text = $note->getChildren()[0];
        $this->queueChanges([
            'toVersion' => 'v2',
            'nodes' => [
                self::rawNode($note->id, 'NOTE', 'root', ['serverId' => 'srv-note', 'title' => 'Title']),
                self::rawNode($text->id, 'LIST_ITEM', $note->id, ['serverId' => 'srv-text', 'text' => 'Body']),
            ],
        ]);

        $this->keep->sync();

        $uploaded = self::arr($this->body()['nodes']);
        self::assertCount(2, $uploaded);
        $ids = array_map(static fn (mixed $n): mixed => self::arr($n)['id'], $uploaded);
        self::assertEqualsCanonicalizing([$note->id, $text->id], $ids);
        self::assertArrayNotHasKey('targetVersion', $this->body());
        self::assertSame('srv-note', $note->serverId);
        self::assertSame($note, $this->keep->get('srv-note'));
        self::assertSame($note, $this->keep->get($note->id));
        self::assertFalse($note->isDirty());
        self::assertSame('Body', $note->getText());
    }

    public function testSecondSyncSendsTheTargetVersionAndOnlyDirtyNodes(): void
    {
        $this->login();
        $this->queueChanges(['toVersion' => 'v1', 'nodes' => [self::rawNode('n1'), self::rawNode('n2')]]);
        $this->keep->sync();
        $note = $this->keep->get('n1');
        self::assertNotNull($note);
        $note->setTitle('Changed');
        $this->queueChanges(['toVersion' => 'v2']);

        $this->keep->sync();

        self::assertSame('v1', $this->body(1)['targetVersion']);
        $uploaded = self::arr($this->body(1)['nodes']);
        self::assertCount(1, $uploaded);
        self::assertSame('n1', self::arr($uploaded[0])['id']);
        self::assertSame('Changed', self::arr($uploaded[0])['title']);
        self::assertFalse($note->isDirty());
    }

    public function testTruncatedResponsesAreFetchedUntilComplete(): void
    {
        $this->login();
        $this->queueChanges(['toVersion' => 'v1', 'truncated' => true, 'nodes' => [self::rawNode('n1')]]);
        $this->queueChanges(['toVersion' => 'v2', 'truncated' => true, 'nodes' => [self::rawNode('n2')]]);
        $this->queueChanges(['toVersion' => 'v3', 'nodes' => [self::rawNode('n3')]]);

        $this->keep->sync();

        self::assertCount(3, $this->http->requests);
        self::assertArrayNotHasKey('targetVersion', $this->body(0));
        self::assertSame('v1', $this->body(1)['targetVersion']);
        self::assertSame('v2', $this->body(2)['targetVersion']);
        self::assertCount(3, $this->keep->all());
    }

    public function testForcedFullResyncIsReported(): void
    {
        $this->login();
        $this->queueChanges(['forceFullResync' => true]);

        $this->expectException(ResyncRequiredException::class);

        $this->keep->sync();
    }

    public function testUpgradeRecommendationIsReported(): void
    {
        $this->login();
        $this->queueChanges(['upgradeRecommended' => true]);

        $this->expectException(UpgradeRecommendedException::class);

        $this->keep->sync();
    }

    public function testResponseWithoutVersionIsASyncError(): void
    {
        $this->login();
        $this->http->queueBody('{"nodes": []}');

        $this->expectException(SyncException::class);

        $this->keep->sync();
    }

    public function testUpdatesAreLoadedIntoKnownNodes(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [self::rawNode('n1', 'NOTE', 'root', ['title' => 'Old'])]]);
        $this->keep->sync();
        $note = $this->keep->get('n1');
        $updated = self::rawNode('n1', 'NOTE', 'root', ['title' => 'New']);
        $this->queueChanges(['toVersion' => 'v2', 'nodes' => [$updated]]);

        $this->keep->sync();

        self::assertSame($note, $this->keep->get('n1'));
        self::assertNotNull($note);
        self::assertSame('New', $note->getTitle());
    }

    public function testNodesWithoutParentIdAreDeletions(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [self::rawNode('n1'), self::rawNode('n2')]]);
        $this->keep->sync();
        $this->queueChanges(['toVersion' => 'v2', 'nodes' => [['id' => 'n1']]]);

        $this->keep->sync();

        self::assertNull($this->keep->get('n1'));
        self::assertNull($this->keep->get('sid-n1'));
        self::assertCount(1, $this->keep->all());
    }

    public function testUnknownNodeTypesAreDiscarded(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [self::rawNode('x1', 'HOLOGRAM'), self::rawNode('n1')]]);

        $this->keep->sync();

        self::assertCount(1, $this->keep->all());
    }

    public function testNodesWithUnknownParentAreASyncError(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [self::rawNode('c1', 'LIST_ITEM', 'missing')]]);

        $this->expectException(SyncException::class);

        $this->keep->sync();
    }

    public function testNodesWithoutIdAreAParseError(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [['type' => 'NOTE']]]);

        $this->expectException(ParseException::class);

        $this->keep->sync();
    }

    public function testListItemsAreAttachedAndIndentedAfterSync(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [
            self::rawNode('l1', 'LIST', 'root', ['title' => 'List']),
            self::rawNode('i1', 'LIST_ITEM', 'l1', ['text' => 'parent', 'sortValue' => '300']),
            self::rawNode(
                'i2',
                'LIST_ITEM',
                'l1',
                ['text' => 'child', 'sortValue' => '200', 'superListItemId' => 'i1'],
            ),
            self::rawNode('i3', 'LIST_ITEM', 'l1', ['text' => 'other', 'sortValue' => '100']),
        ]]);

        $this->keep->sync();

        $list = $this->keep->get('l1');
        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame("☐ parent\n  ☐ child\n☐ other", $list->getText());
        $child = $list->get('i2');
        self::assertInstanceOf(ListItem::class, $child);
        self::assertTrue($child->isIndented());
        self::assertFalse($list->isDirty());
    }

    public function testDedentingIsAppliedOnLaterUpdates(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [
            self::rawNode('l1', 'LIST'),
            self::rawNode('i1', 'LIST_ITEM', 'l1'),
            self::rawNode('i2', 'LIST_ITEM', 'l1', ['superListItemId' => 'i1']),
        ]]);
        $this->keep->sync();
        $this->queueChanges(['toVersion' => 'v2', 'nodes' => [
            self::rawNode('i2', 'LIST_ITEM', 'l1', ['superListItemId' => '']),
        ]]);

        $this->keep->sync();

        $list = $this->keep->get('l1');
        self::assertInstanceOf(ListNode::class, $list);
        $item = $list->get('i2');
        self::assertInstanceOf(ListItem::class, $item);
        self::assertFalse($item->isIndented());
    }

    public function testResyncDiscardsLocalChanges(): void
    {
        $this->login();
        $this->queueChanges(['nodes' => [self::rawNode('n1')]]);
        $this->keep->sync();
        $this->keep->createNote('Local only');
        $this->queueChanges(['toVersion' => 'v9', 'nodes' => [self::rawNode('n2')]]);

        $this->keep->sync(true);

        self::assertArrayNotHasKey('targetVersion', $this->body(1));
        self::assertSame([], $this->body(1)['nodes']);
        self::assertCount(1, $this->keep->all());
        self::assertNotNull($this->keep->get('n2'));
        self::assertNull($this->keep->get('n1'));
    }

    // --- Labels -----------------------------------------------------------------------------------------------

    public function testLabelsAreUploadedWhenDirtyAndHydratedIntoNotes(): void
    {
        $this->login();
        $label = $this->keep->createLabel('Work');
        $this->queueChanges([
            'userInfo' => ['labels' => [[
                'mainId' => $label->id,
                'name' => 'Work',
                'timestamps' => ['created' => '2020-01-01T00:00:00.000Z', 'updated' => '2020-01-01T00:00:00.000Z'],
            ]]],
            'nodes' => [self::rawNode('n1', 'NOTE', 'root', ['labelIds' => [['labelId' => $label->id]]])],
        ]);

        $this->keep->sync();

        $labels = self::arr(self::arr($this->body()['userInfo'])['labels']);
        self::assertCount(1, $labels);
        self::assertSame('Work', self::arr($labels[0])['name']);
        $note = $this->keep->get('n1');
        self::assertNotNull($note);
        self::assertSame([$label], $note->labels->all());
        self::assertSame([$note], $this->keep->find(null, null, [$label]));
        self::assertSame([$note], $this->keep->find(null, null, [$label->id]));
    }

    public function testUnchangedLabelsAreNotUploaded(): void
    {
        $this->login();
        $this->queueChanges(['userInfo' => ['labels' => [[
            'mainId' => 'tag.a',
            'name' => 'A',
            'timestamps' => ['created' => '2020-01-01T00:00:00.000Z', 'updated' => '2020-01-01T00:00:00.000Z'],
        ]]]]);
        $this->keep->sync();
        $this->queueChanges(['toVersion' => 'v2']);

        $this->keep->sync();

        self::assertArrayNotHasKey('userInfo', $this->body(1));
    }

    public function testLabelsMissingFromTheServerResponseAreDropped(): void
    {
        $this->login();
        $this->keep->createLabel('Temp');
        $this->queueChanges(['userInfo' => ['labels' => []]]);

        $this->keep->sync();

        self::assertSame([], $this->keep->labels());
    }

    public function testCreateLabelRejectsDuplicatesCaseInsensitively(): void
    {
        $this->keep->createLabel('Work');

        $this->expectException(LabelException::class);

        $this->keep->createLabel('WORK');
    }

    public function testFindLabel(): void
    {
        $work = $this->keep->createLabel('Work');
        $this->keep->createLabel('Home');

        self::assertSame($work, $this->keep->findLabel('work'));
        self::assertSame($work, $this->keep->findLabel(new Pattern('/^wo/i')));
        self::assertNull($this->keep->findLabel('Nope'));
        self::assertNull($this->keep->findLabel(new Pattern('/zzz/')));
        self::assertNull($this->keep->findLabel('Nope', false));
        $created = $this->keep->findLabel('Nope', true);
        self::assertNotNull($created);
        self::assertSame('Nope', $created->getName());
        self::assertSame($work, $this->keep->getLabel($work->id));
        self::assertNull($this->keep->getLabel('missing'));
        self::assertCount(3, $this->keep->labels());
    }

    public function testDeleteLabelRemovesItFromNotes(): void
    {
        $this->login();
        $label = $this->keep->createLabel('Work');
        $note = $this->keep->createNote('N');
        $note->labels->add($label);
        self::assertSame([$label], $note->labels->all());

        $this->keep->deleteLabel($label->id);

        self::assertTrue($label->isDeleted());
        self::assertSame([], $note->labels->all());
        $this->keep->deleteLabel('unknown');
    }

    // --- Local operations -------------------------------------------------------------------------------------

    public function testCreateNoteIsRegisteredAndDirty(): void
    {
        $note = $this->keep->createNote('Title', 'Text');

        self::assertSame([$note], $this->keep->all());
        self::assertSame($note, $this->keep->get($note->id));
        self::assertSame('Title', $note->getTitle());
        self::assertSame('Text', $note->getText());
        self::assertTrue($note->isDirty());
    }

    public function testCreateListPopulatesItemsInGivenOrder(): void
    {
        $list = $this->keep->createList('Groceries', [['milk', false], ['eggs', true], ['bread', false]]);

        self::assertSame("Groceries\n☐ milk\n☑ eggs\n☐ bread", (string) $list);
        self::assertSame([$list], $this->keep->all());
    }

    public function testAddRejectsNodesThatAreNotTopLevel(): void
    {
        $this->expectException(InvalidException::class);

        $this->keep->add(new ListItem('some-parent'));
    }

    public function testGetReturnsNullForUnknownIds(): void
    {
        self::assertNull($this->keep->get('nope'));
    }

    public function testFind(): void
    {
        $a = $this->keep->createNote('Alpha', 'first body');
        $b = $this->keep->createNote('Beta', 'second BODY');
        $c = $this->keep->createList('Gamma', [['item', false]]);
        $b->setColor(ColorValue::Red);
        $c->setPinned(true);
        $a->setArchived(true);
        $d = $this->keep->createNote('Deleted');
        $d->trash();

        self::assertSame([$a, $b, $c], $this->keep->find());
        self::assertSame([$a, $b, $c, $d], $this->keep->find(trashed: null));
        self::assertSame([$d], $this->keep->find(trashed: true));
        self::assertSame([$a], $this->keep->find('first'));
        self::assertSame([$b], $this->keep->find('BODY'));
        self::assertSame([$a, $b], $this->keep->find(new Pattern('/body/i')));
        self::assertSame([$c], $this->keep->find('item'));
        self::assertSame([$c], $this->keep->find(func: static fn (Node $n): bool => $n instanceof ListNode));
        self::assertSame([$b], $this->keep->find(colors: [ColorValue::Red]));
        self::assertSame([$c], $this->keep->find(pinned: true));
        self::assertSame([$a], $this->keep->find(archived: true));
        self::assertSame([$a, $b, $c], $this->keep->find(labels: []));
        self::assertSame([], $this->keep->find(labels: ['missing']));
    }

    public function testFindLabelsMatchAnyOfTheGivenLabels(): void
    {
        $x = $this->keep->createLabel('X');
        $y = $this->keep->createLabel('Y');
        $a = $this->keep->createNote('A');
        $b = $this->keep->createNote('B');
        $this->keep->createNote('C');
        $a->labels->add($x);
        $b->labels->add($y);

        self::assertSame([$a, $b], $this->keep->find(labels: [$x, $y]));
        self::assertSame([$a], $this->keep->find(labels: [$x]));
        self::assertCount(1, $this->keep->find(labels: []));
    }

    // --- Dump / restore ---------------------------------------------------------------------------------------

    public function testDumpAndRestoreRoundTripWithoutNetwork(): void
    {
        $this->login();
        $this->http->queueBody(json_encode($this->fixture(), JSON_THROW_ON_ERROR));
        $this->keep->sync();
        $state = json_decode(json_encode($this->keep->dump(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        $factory = new Psr17Factory();
        $other = new Keep(new RecordingHttpClient(), $factory, $factory);
        $other->restore($state);

        self::assertCount(39, $other->all());
        self::assertSame($this->titles($this->keep), $this->titles($other));
        self::assertCount(3, $other->labels());
        self::assertSame($state['keep_version'], $other->dump()['keep_version']);
        self::assertSame('AFOfgb7ywdXKsSnjsM3Pvqh5cwd5kTJzaW7azHCugtU=', $state['keep_version']);
    }

    public function testRestoredStateIsLoadedBeforeTheFirstSyncAndSendsItsVersion(): void
    {
        $this->login();
        $this->http->queueBody(json_encode($this->fixture(), JSON_THROW_ON_ERROR));
        $this->keep->sync();
        $state = $this->keep->dump();
        $this->setUp();
        $this->http->queueBody("Auth=tok-1\n");
        $this->queueChanges(['toVersion' => 'v2']);

        $this->keep->authenticate('user@example.com', 'master-token', $state, true, 'dev123');

        self::assertCount(39, $this->keep->all());
        self::assertSame('AFOfgb7ywdXKsSnjsM3Pvqh5cwd5kTJzaW7azHCugtU=', $this->body(1)['targetVersion']);
        self::assertSame([], $this->body(1)['nodes']);
    }

    public function testDumpIncludesUnsyncedChildren(): void
    {
        $note = $this->keep->createNote('T', 'body');

        $dump = $this->keep->dump();

        self::assertNull($dump['keep_version']);
        self::assertCount(2, $dump['nodes']);
        self::assertSame($note->id, $dump['nodes'][0]['id']);
        self::assertTrue($dump['nodes'][0]['_dirty']);
    }

    public function testRestoreRejectsMalformedState(): void
    {
        $this->expectException(InvalidException::class);

        $this->keep->restore(['nodes' => []]);
    }

    public function testRestoreReplacesExistingState(): void
    {
        $this->keep->createNote('Old');

        $this->keep->restore(['keep_version' => 'v5', 'labels' => [], 'nodes' => []]);

        self::assertSame([], $this->keep->all());
        self::assertSame('v5', $this->keep->dump()['keep_version']);
    }

    // --- Media ------------------------------------------------------------------------------------------------

    public function testGetMediaLink(): void
    {
        $this->login();
        $note = new Note();
        $note->serverId = 'note-sid';
        $blob = new Blob($note->id);
        $blob->serverId = 'blob-sid';
        $blob->blob = Blob::fromJson(['type' => 'IMAGE']);
        $note->append($blob);
        $this->http->queueBody('', 302, ['Location' => 'https://lh3.example/m']);

        self::assertSame('https://lh3.example/m', $this->keep->getMediaLink($blob));
        self::assertSame(
            'https://keep.google.com/media/v2/note-sid/blob-sid',
            (string) $this->http->requests[0]->getUri(),
        );
    }

    /** @return list<string> */
    private function titles(Keep $keep): array
    {
        $titles = array_map(static fn (TopLevelNode $n): string => $n->id . ':' . $n->getTitle(), $keep->all());
        sort($titles);

        return $titles;
    }
}
