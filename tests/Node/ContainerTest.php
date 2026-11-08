<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use DateTimeImmutable;
use Gkeepapi\Exception\ParseException;
use Gkeepapi\Node\CheckedListItemsPolicyValue;
use Gkeepapi\Node\Element;
use Gkeepapi\Node\GraveyardStateValue;
use Gkeepapi\Node\Label;
use Gkeepapi\Node\NewListItemPlacementValue;
use Gkeepapi\Node\NodeCollaborators;
use Gkeepapi\Node\NodeLabels;
use Gkeepapi\Node\NodeSettings;
use Gkeepapi\Node\NodeTimestamps;
use Gkeepapi\Node\TimestampsMixin;

final class ContainerTest extends NodeTestCase
{
    public function testTimestampsSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): NodeTimestamps => new NodeTimestamps());
    }

    public function testTimestampsFields(): void
    {
        $n = new NodeTimestamps(0);
        $tz = NodeTimestamps::intToDt(0);
        self::cleanNode($n);

        $n->setCreated($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getCreated());

        self::cleanNode($n);
        $n->setDeleted($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getDeleted());

        self::cleanNode($n);
        $n->setTrashed($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getTrashed());

        self::cleanNode($n);
        $n->setUpdated($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getUpdated());

        self::cleanNode($n);
        $n->setEdited($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getEdited());
    }

    public function testTimestampStringConversion(): void
    {
        $dt = NodeTimestamps::strToDt('2020-02-27T04:14:18.261Z');

        self::assertSame(1582776858, $dt->getTimestamp());
        self::assertSame('2020-02-27T04:14:18.261000Z', NodeTimestamps::dtToStr($dt));
        self::assertSame('1970-01-01T00:00:00.000000Z', NodeTimestamps::intToStr(0));
        self::assertEquals(NodeTimestamps::intToDt(0), NodeTimestamps::strToDt(null));
        self::assertSame('1970-01-01T00:00:01.500000Z', NodeTimestamps::intToStr(1.5));
    }

    public function testInvalidTimestampStringIsRejected(): void
    {
        $this->expectException(\ValueError::class);

        NodeTimestamps::strToDt('yesterday');
    }

    public function testSettingsSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): NodeSettings => new NodeSettings());
    }

    public function testSettingsFields(): void
    {
        $n = new NodeSettings();
        self::cleanNode($n);

        $n->setNewListitemPlacement(NewListItemPlacementValue::Top);
        self::assertTrue($n->isDirty());
        self::assertSame(NewListItemPlacementValue::Top, $n->getNewListitemPlacement());

        self::cleanNode($n);
        $n->setGraveyardState(GraveyardStateValue::Expanded);
        self::assertTrue($n->isDirty());
        self::assertSame(GraveyardStateValue::Expanded, $n->getGraveyardState());

        self::cleanNode($n);
        $n->setCheckedListitemsPolicy(CheckedListItemsPolicyValue::Default);
        self::assertTrue($n->isDirty());
        self::assertSame(CheckedListItemsPolicyValue::Default, $n->getCheckedListitemsPolicy());
    }

    public function testLabelsSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): NodeLabels => new NodeLabels());
    }

    public function testLabelsFields(): void
    {
        $n = new NodeLabels();
        $sub = new Label();
        $sub->setName('Label');
        self::cleanNode($sub);
        self::cleanNode($n);

        $n->add($sub);

        self::assertTrue($n->isDirty());
        self::assertSame($sub, $n->get($sub->id));
        self::assertSame([$sub], $n->all());
        self::assertCount(1, $n);

        self::cleanNode($n);
        $n->remove($sub);

        self::assertTrue($n->isDirty());
        self::assertSame([], $n->all());
    }

    public function testLabelsLoadRestoresDirtyFlagFromTrailingBool(): void
    {
        $n = new NodeLabels();
        $n->load([['labelId' => 'tag.a'], true]);

        self::assertTrue($n->isDirty());
        self::assertCount(1, $n);
        self::assertNull($n->get('tag.a'));
    }

    public function testCollaboratorsSaveLoad(): void
    {
        $a = new NodeCollaborators();
        $b = new NodeCollaborators();
        $b->load(...$a->save());

        self::assertSame($a->save(), $b->save());
    }

    public function testCollaboratorsAddRemove(): void
    {
        $n = new NodeCollaborators();

        $n->add('user@example.com');
        self::assertTrue($n->isDirty());
        self::assertCount(1, $n);
        self::assertSame(['user@example.com'], $n->all());
        [, $requests] = $n->save();
        self::assertSame([['email' => 'user@example.com', 'type' => 'WR']], $requests);

        $n->remove('user@example.com');
        self::assertCount(0, $n);
    }

    public function testCollaboratorsLoadedRoleCanBeRemovedViaShareRequest(): void
    {
        $n = new NodeCollaborators();
        $n->load([['email' => 'owner@example.com', 'role' => 'O'], ['role' => 'W']]);
        self::assertSame(['owner@example.com'], $n->all());

        $n->remove('owner@example.com');

        self::assertSame([], $n->all());
        [$collaborators, $requests] = $n->save(false);
        self::assertSame([], $collaborators);
        self::assertSame([['email' => 'owner@example.com', 'type' => 'RM'], true], $requests);
    }

    public function testLabelFields(): void
    {
        $n = new Label();
        self::cleanNode($n);

        $n->setName('name');
        self::assertTrue($n->isDirty());
        self::assertSame('name', $n->getName());
        self::assertSame('name', (string) $n);

        self::cleanNode($n);
        $tz = NodeTimestamps::intToDt(0);
        $n->setMerged($tz);
        self::assertTrue($n->isDirty());
        self::assertEquals($tz, $n->getMerged());
    }

    public function testLabelSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): Label => new Label());
    }

    public function testLabelIdFormat(): void
    {
        self::assertMatchesRegularExpression('/^tag\.[a-z0-9]{12}\.[0-9a-f]+$/', (new Label())->id);
    }

    public function testElementSaveIncludesDirtyWhenNotCleaning(): void
    {
        self::assertArrayHasKey('_dirty', (new Element())->save(false));
        self::assertArrayNotHasKey('_dirty', (new Element())->save());
    }

    public function testElementLoadWrapsMissingKeysInParseException(): void
    {
        $this->expectException(ParseException::class);

        (new NodeSettings())->load([]);
    }

    public function testTimestampsMixin(): void
    {
        $n = new class extends Element {
            use TimestampsMixin;

            public function __construct()
            {
                $this->timestamps = new NodeTimestamps(0);
            }
        };
        $epoch = NodeTimestamps::intToDt(0);

        $n->touch();
        self::assertTrue($n->isDirty());
        self::assertGreaterThan($epoch, $n->timestamps->getUpdated());
        self::assertEquals($epoch, $n->timestamps->getEdited());

        $n->touch(true);
        self::assertGreaterThan($epoch, $n->timestamps->getEdited());

        self::assertFalse($n->isDeleted());
        $n->timestamps->setDeleted(null);
        self::assertFalse($n->isDeleted());
        $n->timestamps->setDeleted($epoch);
        self::assertFalse($n->isDeleted());
        $n->timestamps->setDeleted(NodeTimestamps::intToDt(1));
        self::assertTrue($n->isDeleted());

        self::assertFalse($n->isTrashed());
        $n->timestamps->setTrashed(NodeTimestamps::intToDt(1));
        self::assertTrue($n->isTrashed());
        $n->untrash();
        self::assertFalse($n->isTrashed());
        $n->trash();
        self::assertTrue($n->isTrashed());
        self::assertInstanceOf(DateTimeImmutable::class, $n->timestamps->getTrashed());

        $n->undelete();
        self::assertNull($n->timestamps->getDeleted());
        $n->delete();
        self::assertTrue($n->isDeleted());
    }
}
