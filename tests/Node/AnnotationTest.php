<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Node\Annotation;
use Gkeepapi\Node\Category;
use Gkeepapi\Node\CategoryValue;
use Gkeepapi\Node\Context;
use Gkeepapi\Node\NodeAnnotations;
use Gkeepapi\Node\TaskAssist;
use Gkeepapi\Node\WebLink;

final class AnnotationTest extends NodeTestCase
{
    public function testSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): Annotation => new Annotation());
        self::assertSaveLoadRoundTrips(static fn (): WebLink => new WebLink());
        self::assertSaveLoadRoundTrips(static function (): Category {
            $c = new Category();
            $c->setCategory(CategoryValue::Books);

            return $c;
        });
        self::assertSaveLoadRoundTrips(static fn (): TaskAssist => new TaskAssist());
    }

    public function testWeblinkFields(): void
    {
        $n = new WebLink();
        self::cleanNode($n);

        self::assertNull($n->getTitle());
        self::assertSame('', $n->getUrl());
        self::assertNull($n->getImageUrl());
        self::assertSame('', $n->getProvenanceUrl());
        self::assertNull($n->getDescription());

        $n->setTitle('Title');
        self::assertTrue($n->isDirty());
        self::assertSame('Title', $n->getTitle());

        self::cleanNode($n);
        $n->setUrl('https://url.url');
        self::assertTrue($n->isDirty());
        self::assertSame('https://url.url', $n->getUrl());

        self::cleanNode($n);
        $n->setImageUrl('https://img.url');
        self::assertTrue($n->isDirty());
        self::assertSame('https://img.url', $n->getImageUrl());

        self::cleanNode($n);
        $n->setProvenanceUrl('https://provenance.url');
        self::assertTrue($n->isDirty());
        self::assertSame('https://provenance.url', $n->getProvenanceUrl());

        self::cleanNode($n);
        $n->setDescription('Description');
        self::assertTrue($n->isDirty());
        self::assertSame('Description', $n->getDescription());
    }

    public function testCategoryFields(): void
    {
        $n = new Category();
        $n->setCategory(CategoryValue::TV);
        self::cleanNode($n);

        $n->setCategory(CategoryValue::Books);

        self::assertTrue($n->isDirty());
        self::assertSame(CategoryValue::Books, $n->getCategory());
    }

    public function testTaskAssistFields(): void
    {
        $n = new TaskAssist();
        self::cleanNode($n);

        $n->setSuggest('UNKNOWN');

        self::assertTrue($n->isDirty());
        self::assertSame('UNKNOWN', $n->getSuggest());
    }

    public function testContextSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): Context => new Context());
    }

    public function testContextSubannotations(): void
    {
        $n = new class extends Context {
            public function inject(string $key, Annotation $annotation): void
            {
                $this->entries[$key] = $annotation;
            }
        };
        $sub = new WebLink();
        $sub->id = null;
        $sub->setUrl('https://url.url');
        $n->inject('x', $sub);

        self::assertTrue($n->isDirty());
        self::assertSame([$sub], $n->all());

        $n->load($n->save());

        self::assertCount(1, $n->all());
    }

    public function testFromJsonDispatchesOnKey(): void
    {
        self::assertInstanceOf(WebLink::class, NodeAnnotations::fromJson([
            'webLink' => ['url' => 'u', 'provenanceUrl' => 'p'],
        ]));
        self::assertInstanceOf(Category::class, NodeAnnotations::fromJson([
            'topicCategory' => ['category' => 'TV'],
        ]));
        self::assertInstanceOf(TaskAssist::class, NodeAnnotations::fromJson([
            'taskAssist' => ['suggestType' => 'GROCERY_ITEM'],
        ]));
        self::assertInstanceOf(Context::class, NodeAnnotations::fromJson(['context' => []]));
        self::assertNull(NodeAnnotations::fromJson(['somethingNew' => []]));
    }

    public function testNodeAnnotationsSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): NodeAnnotations => new NodeAnnotations());
    }

    public function testNodeAnnotationsFields(): void
    {
        $n = new NodeAnnotations();
        self::cleanNode($n);

        $n->setCategory(null);
        self::assertTrue($n->isDirty());
        self::assertNull($n->getCategory());

        $sub = new Category();
        $sub->setCategory(CategoryValue::Books);
        self::cleanNode($sub);
        self::cleanNode($n);
        $n->append($sub);
        self::assertTrue($n->isDirty());
        self::assertSame(CategoryValue::Books, $n->getCategory());

        self::cleanNode($n);
        $sub->setCategory(CategoryValue::TV);
        self::assertTrue($n->isDirty());
        self::assertSame(CategoryValue::TV, $n->getCategory());

        self::cleanNode($n);
        $n->remove($sub);
        self::assertTrue($n->isDirty());
        self::assertSame([], $n->getLinks());

        $link = new WebLink();
        self::cleanNode($link);
        self::cleanNode($n);
        $n->append($link);
        self::assertTrue($n->isDirty());
        self::assertSame([$link], $n->getLinks());
        self::assertCount(1, $n);
    }

    public function testNodeAnnotationsSetCategoryCreatesAndRemovesNode(): void
    {
        $n = new NodeAnnotations();

        $n->setCategory(CategoryValue::Food);
        self::assertSame(CategoryValue::Food, $n->getCategory());
        self::assertCount(1, $n);

        $n->setCategory(null);
        self::assertNull($n->getCategory());
        self::assertCount(0, $n);
    }
}
