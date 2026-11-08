<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Api;

use Gkeepapi\Api\KeepAPI;
use Gkeepapi\Exception\APIException;

final class KeepApiTest extends ApiTestCase
{
    private function api(): KeepAPI
    {
        return new KeepAPI($this->http, $this->factory, $this->factory, $this->loggedInAuth(), $this->sleeper());
    }

    public function testInitialSyncRequest(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"nodes": [], "toVersion": "v1"}');

        $res = $api->changes();

        self::assertSame(['nodes' => [], 'toVersion' => 'v1'], $res);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://www.googleapis.com/notes/v1/changes', (string) $request->getUri());
        $body = $this->json();
        self::assertSame([], $body['nodes']);
        self::assertArrayNotHasKey('targetVersion', $body);
        self::assertArrayNotHasKey('userInfo', $body);
        self::assertIsString($body['clientTimestamp']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $body['clientTimestamp']);
    }

    public function testRequestHeaderMimicsTheAndroidApp(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->changes();

        $header = self::arr($this->json()['requestHeader']);
        self::assertSame('ANDROID', $header['clientPlatform']);
        self::assertSame(['major' => '9', 'minor' => '9', 'build' => '9', 'revision' => '9'], $header['clientVersion']);
        self::assertIsString($header['clientSessionId']);
        self::assertMatchesRegularExpression('/^s--\d+--\d{10}$/', $header['clientSessionId']);
        $types = array_map(
            static fn (mixed $c): mixed => self::arr($c)['type'],
            self::arr($header['capabilities']),
        );
        self::assertSame(['NC', 'PI', 'LB', 'AN', 'SH', 'DR', 'TR', 'IN', 'SNB', 'MI', 'CO'], $types);
    }

    public function testSessionIdIsStablePerClientAndDiffersBetweenClients(): void
    {
        $first = $this->api();
        $second = $this->api();
        $this->http->queueBody('{}')->queueBody('{}')->queueBody('{}');
        $first->changes();
        $first->changes();
        $second->changes();

        $ids = array_map(
            fn (int $i): mixed => self::arr($this->json($i)['requestHeader'])['clientSessionId'],
            [0, 1, 2],
        );
        self::assertSame($ids[0], $ids[1]);
        self::assertNotSame($ids[0], $ids[2]);
    }

    public function testUploadsNodesLabelsAndTargetVersion(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');
        $node = ['id' => 'n1', 'type' => 'NOTE'];
        $label = ['mainId' => 'tag.a', 'name' => 'x'];

        $api->changes('v7', [$node], [$label]);

        $body = $this->json();
        self::assertSame([$node], $body['nodes']);
        self::assertSame('v7', $body['targetVersion']);
        self::assertSame(['labels' => [$label]], $body['userInfo']);
    }

    public function testServerErrorsAreRaised(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"error": {"code": 403, "message": "denied"}}');

        $this->expectException(APIException::class);
        $this->expectExceptionMessage('denied');

        $api->changes();
    }
}
