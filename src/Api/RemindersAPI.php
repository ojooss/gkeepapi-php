<?php

declare(strict_types=1);

namespace Gkeepapi\Api;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Exception\APIException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Low level Google Reminders API client. Mimics the Android Google Keep app.
 *
 * Port of gkeepapi/__init__.py `RemindersAPI` (MIT, (c) Kai Zhong / kiwiz).
 */
class RemindersAPI extends API
{
    public const API_URL = 'https://www.googleapis.com/reminders/v1internal/reminders/';

    /** @var array<string, mixed> */
    private readonly array $staticParams;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?APIAuth $auth = null,
        ?\Closure $sleeper = null,
    ) {
        parent::__construct(self::API_URL, $httpClient, $requestFactory, $streamFactory, $auth, $sleeper);
        $this->staticParams = [
            'taskList' => [
                ['systemListId' => 'MEMENTO'],
            ],
            'requestParameters' => [
                'userAgentStructured' => [
                    'clientApplication' => 'KEEP',
                    'clientApplicationVersion' => [
                        'major' => '9',
                        'minor' => '9.9.9.9',
                    ],
                    'clientPlatform' => 'ANDROID',
                ],
            ],
        ];
    }

    /**
     * Create a new reminder.
     *
     * @param string $nodeId The note ID.
     * @param string $nodeServerId The note server ID.
     * @param \DateTimeInterface $dtime The due date of this reminder.
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function create(string $nodeId, string $nodeServerId, \DateTimeInterface $dtime): array
    {
        $params = $this->staticParams + [
            'task' => $this->task($nodeId, $nodeServerId, $dtime),
            'taskId' => [
                'clientAssignedId' => 'KEEP/v2/' . $nodeServerId,
            ],
        ];

        return $this->send($this->baseUrl . 'create', 'POST', $params);
    }

    /**
     * Update an existing reminder.
     *
     * @param string $nodeId The note ID.
     * @param string $nodeServerId The note server ID.
     * @param \DateTimeInterface $dtime The due date of this reminder.
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function updateInternal(string $nodeId, string $nodeServerId, \DateTimeInterface $dtime): array
    {
        $params = $this->staticParams + [
            'newTask' => $this->task($nodeId, $nodeServerId, $dtime),
            'taskId' => [
                'clientAssignedId' => 'KEEP/v2/' . $nodeServerId,
            ],
            'updateMask' => [
                'updateField' => [
                    'ARCHIVED',
                    'DUE_DATE',
                    'EXTENSIONS',
                    'LOCATION',
                    'TITLE',
                ],
            ],
        ];

        return $this->send($this->baseUrl . 'update', 'POST', $params);
    }

    /**
     * Delete an existing reminder.
     *
     * @param string $nodeServerId The note server ID.
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function delete(string $nodeServerId): array
    {
        $params = $this->staticParams + [
            'batchedRequest' => [
                [
                    'deleteTask' => [
                        'taskId' => [
                            ['clientAssignedId' => 'KEEP/v2/' . $nodeServerId],
                        ],
                    ],
                ],
            ],
        ];

        return $this->send($this->baseUrl . 'batchmutate', 'POST', $params);
    }

    /**
     * List current reminders.
     *
     * @param bool $master Whether to list only master reminders (including archived) instead of recent instances.
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function list(bool $master = true): array
    {
        $params = $this->staticParams;
        if ($master) {
            $params += [
                'recurrenceOptions' => [
                    'collapseMode' => 'MASTER_ONLY',
                ],
                'includeArchived' => true,
                'includeDeleted' => false,
            ];
        } else {
            $currentTime = microtime(true);
            $startTime = (int) (($currentTime - (365 * 24 * 60 * 60)) * 1000);
            $endTime = (int) (($currentTime + (24 * 60 * 60)) * 1000);
            $params += [
                'recurrenceOptions' => [
                    'collapseMode' => 'INSTANCES_ONLY',
                    'recurrencesOnly' => true,
                ],
                'includeArchived' => false,
                'includeCompleted' => false,
                'includeDeleted' => false,
                'dueAfterMs' => $startTime,
                'dueBeforeMs' => $endTime,
                'recurrenceId' => [],
            ];
        }

        return $this->send($this->baseUrl . 'list', 'POST', $params);
    }

    /**
     * Get reminder changes.
     *
     * @param string $storageVersion The local storage version.
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function history(string $storageVersion): array
    {
        $params = [
            'storageVersion' => $storageVersion,
            'includeSnoozePresetUpdates' => true,
        ] + $this->staticParams;

        return $this->send($this->baseUrl . 'history', 'POST', $params);
    }

    /**
     * Sync up changes to reminders.
     *
     * @return array<array-key, mixed>
     * @throws APIException If the server returns an error.
     */
    public function update(): array
    {
        return $this->send($this->baseUrl . 'update', 'POST', new \stdClass());
    }

    /** @return array<string, mixed> */
    private function task(string $nodeId, string $nodeServerId, \DateTimeInterface $dtime): array
    {
        return [
            'dueDate' => [
                'year' => (int) $dtime->format('Y'),
                'month' => (int) $dtime->format('n'),
                'day' => (int) $dtime->format('j'),
                'time' => [
                    'hour' => (int) $dtime->format('G'),
                    'minute' => (int) $dtime->format('i'),
                    'second' => (int) $dtime->format('s'),
                ],
            ],
            'snoozed' => true,
            'extensions' => [
                'keepExtension' => [
                    'reminderVersion' => 'V2',
                    'clientNoteId' => $nodeId,
                    'serverNoteId' => $nodeServerId,
                ],
            ],
        ];
    }
}
