<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Api;

use Gkeepapi\Api\MediaAPI;
use Gkeepapi\Exception\APIException;
use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Node\Blob;
use Gkeepapi\Node\Note;
use Gkeepapi\Node\NodeBlob;

final class MediaApiTest extends ApiTestCase
{
    private function api(): MediaAPI
    {
        return new MediaAPI($this->http, $this->factory, $this->factory, $this->loggedInAuth());
    }

    private function blob(?NodeBlob $descriptor, bool $synced = true): Blob
    {
        $note = new Note();
        $note->serverId = $synced ? 'note-sid' : null;
        $blob = new Blob($note->id);
        $blob->serverId = $synced ? 'blob-sid' : null;
        $blob->blob = $descriptor;
        $note->append($blob);

        return $blob;
    }

    public function testReturnsTheRedirectLocationWithoutFollowingIt(): void
    {
        $api = $this->api();
        $this->http->queueBody('', 302, ['Location' => 'https://lh3.example/media?x=1']);
        $blob = $this->blob(Blob::fromJson(['type' => 'IMAGE']));

        $url = $api->get($blob);

        self::assertSame('https://lh3.example/media?x=1', $url);
        $request = $this->http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://keep.google.com/media/v2/note-sid/blob-sid', (string) $request->getUri());
        self::assertSame('OAuth tok-1', $request->getHeaderLine('Authorization'));
    }

    public function testDrawingUrlsIncludeTheDrawingId(): void
    {
        $api = $this->api();
        $this->http->queueBody('', 302, ['Location' => 'https://lh3.example/d']);
        $blob = $this->blob(Blob::fromJson([
            'type' => 'DRAWING',
            'drawingInfo' => ['drawingId' => 'draw-1', 'snapshotData' => ['type' => 'IMAGE']],
        ]));

        $api->get($blob);

        self::assertSame(
            'https://keep.google.com/media/v2/note-sid/blob-sid/draw-1',
            (string) $this->http->requests[0]->getUri(),
        );
    }

    public function testMissingRedirectIsAnError(): void
    {
        $api = $this->api();
        $this->http->queueBody('', 404);

        try {
            $api->get($this->blob(Blob::fromJson(['type' => 'AUDIO'])));
            self::fail('Expected APIException');
        } catch (APIException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testBlobsThatWereNeverSyncedAreRejectedBeforeAnyRequest(): void
    {
        $api = $this->api();

        try {
            $api->get($this->blob(Blob::fromJson(['type' => 'AUDIO']), false));
            self::fail('Expected InvalidException');
        } catch (InvalidException) {
            self::assertSame([], $this->http->requests);
        }
    }
}
