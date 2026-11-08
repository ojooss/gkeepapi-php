<?php

declare(strict_types=1);

namespace Gkeepapi\Api;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Exception\APIException;
use Gkeepapi\Node\NodeTimestamps;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Low level Google Keep API client. Mimics the Android Google Keep app.
 *
 * Port of gkeepapi/__init__.py `KeepAPI` (MIT, (c) Kai Zhong / kiwiz).
 */
class KeepAPI extends API
{
    public const API_URL = 'https://www.googleapis.com/notes/v1/';

    private readonly string $sessionId;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?APIAuth $auth = null,
        ?\Closure $sleeper = null,
    ) {
        parent::__construct(self::API_URL, $httpClient, $requestFactory, $streamFactory, $auth, $sleeper);
        $this->sessionId = $this->generateId(microtime(true));
    }

    /**
     * Sync up (and down) all changes.
     *
     * @param string|null $targetVersion The local change version.
     * @param list<array<array-key, mixed>> $nodes A list of raw nodes to sync up to the server.
     * @param list<array<array-key, mixed>> $labels A list of raw labels to sync up to the server.
     * @return array<array-key, mixed> Description of all changes.
     * @throws APIException If the server returns an error.
     */
    public function changes(?string $targetVersion = null, array $nodes = [], array $labels = []): array
    {
        $currentTime = microtime(true);

        // Initialize request parameters.
        $params = [
            'nodes' => $nodes,
            'clientTimestamp' => NodeTimestamps::intToStr($currentTime),
            'requestHeader' => [
                'clientSessionId' => $this->sessionId,
                'clientPlatform' => 'ANDROID',
                'clientVersion' => [
                    'major' => '9',
                    'minor' => '9',
                    'build' => '9',
                    'revision' => '9',
                ],
                'capabilities' => [
                    ['type' => 'NC'], // Color support (Send note color)
                    ['type' => 'PI'], // Pinned support (Send note pinned)
                    ['type' => 'LB'], // Labels support (Send note labels)
                    ['type' => 'AN'], // Annotations support (Send annotations)
                    ['type' => 'SH'], // Sharing support
                    ['type' => 'DR'], // Drawing support
                    ['type' => 'TR'], // Trash support (Stop setting the delete timestamp)
                    ['type' => 'IN'], // Indentation support (Send listitem parent)
                    ['type' => 'SNB'], // Allows modification of shared notes?
                    ['type' => 'MI'], // Concise blob info?
                    ['type' => 'CO'], // VSS_SUCCEEDED when off?
                ],
            ],
        ];

        // Add the targetVersion if set. This tells the server what version the client is currently at.
        if ($targetVersion !== null) {
            $params['targetVersion'] = $targetVersion;
        }

        // Add any new or updated labels to the request.
        if ($labels !== []) {
            $params['userInfo'] = ['labels' => $labels];
        }

        return $this->send($this->baseUrl . 'changes', 'POST', $params);
    }

    private function generateId(float $tz): string
    {
        return sprintf('s--%d--%d', (int) ($tz * 1000), random_int(1000000000, 9999999999));
    }
}
