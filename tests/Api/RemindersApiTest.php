<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Api;

use Gkeepapi\Api\RemindersAPI;

final class RemindersApiTest extends ApiTestCase
{
    private function api(): RemindersAPI
    {
        return new RemindersAPI($this->http, $this->factory, $this->factory, $this->loggedInAuth());
    }

    /** @return array<array-key, mixed> */
    private function post(string $suffix): array
    {
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://www.googleapis.com/reminders/v1internal/reminders/' . $suffix,
            (string) $request->getUri(),
        );

        return $this->json();
    }

    /** @param array<array-key, mixed> $body */
    private function assertStaticParams(array $body): void
    {
        self::assertSame([['systemListId' => 'MEMENTO']], $body['taskList']);
        $agent = self::arr(self::arr($body['requestParameters'])['userAgentStructured']);
        self::assertSame('KEEP', $agent['clientApplication']);
    }

    public function testCreate(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->create('n1', 'sid1', new \DateTimeImmutable('2030-02-03 04:05:06'));

        $body = $this->post('create');
        $this->assertStaticParams($body);
        self::assertSame(['clientAssignedId' => 'KEEP/v2/sid1'], $body['taskId']);
        $task = self::arr($body['task']);
        self::assertSame(
            ['year' => 2030, 'month' => 2, 'day' => 3, 'time' => ['hour' => 4, 'minute' => 5, 'second' => 6]],
            $task['dueDate'],
        );
        self::assertTrue($task['snoozed']);
        self::assertSame(
            ['keepExtension' => ['reminderVersion' => 'V2', 'clientNoteId' => 'n1', 'serverNoteId' => 'sid1']],
            $task['extensions'],
        );
    }

    public function testUpdateInternal(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->updateInternal('n1', 'sid1', new \DateTimeImmutable('2030-12-31 23:59:58'));

        $body = $this->post('update');
        $this->assertStaticParams($body);
        self::assertSame(['clientAssignedId' => 'KEEP/v2/sid1'], $body['taskId']);
        self::assertSame(
            ['ARCHIVED', 'DUE_DATE', 'EXTENSIONS', 'LOCATION', 'TITLE'],
            self::arr($body['updateMask'])['updateField'],
        );
        self::assertSame(12, self::arr(self::arr($body['newTask'])['dueDate'])['month']);
        self::assertArrayNotHasKey('task', $body);
    }

    public function testDelete(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->delete('sid1');

        $body = $this->post('batchmutate');
        $this->assertStaticParams($body);
        self::assertSame(
            [['deleteTask' => ['taskId' => [['clientAssignedId' => 'KEEP/v2/sid1']]]]],
            $body['batchedRequest'],
        );
    }

    public function testListMaster(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"task": []}');

        self::assertSame(['task' => []], $api->list());

        $body = $this->post('list');
        $this->assertStaticParams($body);
        self::assertSame(['collapseMode' => 'MASTER_ONLY'], $body['recurrenceOptions']);
        self::assertTrue($body['includeArchived']);
        self::assertFalse($body['includeDeleted']);
    }

    public function testListInstancesUsesAOneYearWindowEndingTomorrow(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');
        $before = microtime(true);

        $api->list(false);

        $body = $this->post('list');
        self::assertSame(
            ['collapseMode' => 'INSTANCES_ONLY', 'recurrencesOnly' => true],
            $body['recurrenceOptions'],
        );
        self::assertFalse($body['includeArchived']);
        self::assertFalse($body['includeCompleted']);
        self::assertSame([], $body['recurrenceId']);
        self::assertEqualsWithDelta(($before - 365 * 86400) * 1000, $body['dueAfterMs'], 10_000);
        self::assertEqualsWithDelta(($before + 86400) * 1000, $body['dueBeforeMs'], 10_000);
    }

    public function testHistory(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->history('storage-1');

        $body = $this->post('history');
        $this->assertStaticParams($body);
        self::assertSame('storage-1', $body['storageVersion']);
        self::assertTrue($body['includeSnoozePresetUpdates']);
    }

    public function testUpdateSendsAnEmptyJsonObject(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->update();

        $this->post('update');
        self::assertSame('{}', (string) $this->http->requests[0]->getBody());
    }
}
