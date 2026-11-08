<?php

declare(strict_types=1);

namespace Gkeepapi\Tests;

use Gkeepapi\Keep;
use Gkeepapi\Tests\Http\RecordingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

abstract class KeepTestCase extends TestCase
{
    protected RecordingHttpClient $http;
    protected Keep $keep;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new RecordingHttpClient();
        $this->keep = new Keep($this->http, $factory, $factory, static function (): void {
        });
    }

    /**
     * Authenticate without syncing and forget the OAuth request.
     */
    protected function login(): void
    {
        $this->http->queueBody("Auth=tok-1\n");
        $this->keep->authenticate('user@example.com', 'master-token', null, false, 'dev123');
        $this->http->requests = [];
    }

    /**
     * @param array<array-key, mixed> $changes
     */
    protected function queueChanges(array $changes): void
    {
        $changes += ['toVersion' => 'v1', 'truncated' => false, 'forceFullResync' => false];
        $this->http->queueBody(json_encode($changes, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function rawNode(
        string $id,
        string $type = 'NOTE',
        string $parentId = 'root',
        array $extra = [],
    ): array {
        return $extra + [
            'kind' => 'notes#node',
            'id' => $id,
            'serverId' => 'sid-' . $id,
            'parentId' => $parentId,
            'type' => $type,
            'timestamps' => [
                'kind' => 'notes#timestamps',
                'created' => '2020-01-01T00:00:00.000Z',
                'updated' => '2020-01-01T00:00:00.000Z',
            ],
            'nodeSettings' => [
                'newListItemPlacement' => 'BOTTOM',
                'checkedListItemsPolicy' => 'GRAVEYARD',
                'graveyardState' => 'EXPANDED',
            ],
            'annotationsGroup' => ['kind' => 'notes#annotationsGroup'],
            'sortValue' => '100',
            'baseVersion' => '1',
        ];
    }

    /**
     * JSON body of the n-th recorded request.
     *
     * @return array<array-key, mixed>
     */
    protected function body(int $index = 0): array
    {
        $data = json_decode((string) $this->http->requests[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<array-key, mixed> */
    protected static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
