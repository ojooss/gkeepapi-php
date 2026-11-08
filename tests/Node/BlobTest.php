<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Node\Blob;
use Gkeepapi\Node\NodeAudio;
use Gkeepapi\Node\NodeDrawing;
use Gkeepapi\Node\NodeImage;
use Gkeepapi\Node\Note;

final class BlobTest extends NodeTestCase
{
    public function testSaveLoad(): void
    {
        self::assertSaveLoadRoundTrips(static fn (): NodeImage => new NodeImage());
        self::assertSaveLoadRoundTrips(static fn (): NodeDrawing => new NodeDrawing());
        self::assertSaveLoadRoundTrips(static fn (): NodeAudio => new NodeAudio());
    }

    public function testFromJsonBuildsBlobByType(): void
    {
        self::assertInstanceOf(NodeAudio::class, Blob::fromJson(['type' => 'AUDIO', 'length' => 5]));
        self::assertInstanceOf(NodeImage::class, Blob::fromJson(['type' => 'IMAGE']));
        self::assertInstanceOf(NodeDrawing::class, Blob::fromJson(['type' => 'DRAWING']));
    }

    public function testFromJsonReturnsNullForMissingOrUnknown(): void
    {
        self::assertNull(Blob::fromJson(null));
        self::assertNull(Blob::fromJson([]));
        self::assertNull(Blob::fromJson(['type' => 'VIDEO']));
    }

    public function testAudioAndImageExposeLoadedValues(): void
    {
        $audio = Blob::fromJson(['type' => 'AUDIO', 'length' => 42, 'blob_id' => 'b1', 'mimetype' => 'audio/3gpp']);
        $image = Blob::fromJson([
            'type' => 'IMAGE',
            'width' => 640,
            'height' => 480,
            'byte_size' => 1234,
            'extracted_text' => 'hello',
            'is_uploaded' => true,
        ]);

        self::assertInstanceOf(NodeAudio::class, $audio);
        self::assertSame(42, $audio->getLength());
        self::assertSame('b1', $audio->save()['blob_id']);
        self::assertInstanceOf(NodeImage::class, $image);
        self::assertSame(640, $image->getWidth());
        self::assertSame(480, $image->getHeight());
        self::assertSame(1234, $image->getByteSize());
        self::assertSame('hello', $image->getExtractedText());
        self::assertTrue($image->isUploaded());
    }

    public function testImageUrlIsNotImplemented(): void
    {
        $this->expectException(\BadMethodCallException::class);

        (new NodeImage())->getUrl();
    }

    public function testDrawingExtractedTextComesFromSnapshot(): void
    {
        $drawing = Blob::fromJson([
            'type' => 'DRAWING',
            'drawingInfo' => [
                'drawingId' => 'd1',
                'snapshotData' => ['type' => 'IMAGE', 'extracted_text' => 'scribble'],
            ],
        ]);

        self::assertInstanceOf(NodeDrawing::class, $drawing);
        self::assertSame('scribble', $drawing->getExtractedText());
        self::assertSame('d1', self::arr($drawing->save()['drawingInfo'])['drawingId']);
        self::assertSame('', (new NodeDrawing())->getExtractedText());
    }

    public function testBlobNodeLoadsAndSavesItsDescriptor(): void
    {
        $raw = [
            'id' => 'b',
            'parentId' => 'n',
            'kind' => 'notes#node',
            'type' => 'BLOB',
            'timestamps' => ['created' => '2000-01-01T00:00:00.000Z', 'updated' => '2000-01-01T00:00:00.000Z'],
            'nodeSettings' => [
                'newListItemPlacement' => 'TOP',
                'graveyardState' => 'COLLAPSED',
                'checkedListItemsPolicy' => 'DEFAULT',
            ],
            'annotationsGroup' => [],
            'blob' => ['type' => 'IMAGE', 'width' => 1, 'height' => 2, 'byte_size' => 3],
        ];
        $blob = new Blob();

        $blob->load($raw);

        self::assertInstanceOf(NodeImage::class, $blob->blob);
        self::assertSame('IMAGE', self::arr($blob->save()['blob'])['type']);
    }

    public function testTopLevelNodeFiltersBlobsByType(): void
    {
        $note = new Note();
        $image = new Blob();
        $image->blob = new NodeImage();
        $drawing = new Blob();
        $drawing->blob = new NodeDrawing();
        $audio = new Blob();
        $audio->blob = new NodeAudio();
        foreach ([$image, $drawing, $audio] as $blob) {
            $note->append($blob);
        }

        self::assertSame([$image], $note->getImages());
        self::assertSame([$drawing], $note->getDrawings());
        self::assertSame([$audio], $note->getAudio());
        self::assertCount(3, $note->getBlobs());
    }
}
