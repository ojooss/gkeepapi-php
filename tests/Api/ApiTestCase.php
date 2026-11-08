<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Api;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Auth\GoogleKey;
use Gkeepapi\Auth\GpsOAuth;
use Gkeepapi\Tests\Http\RecordingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected RecordingHttpClient $http;
    protected Psr17Factory $factory;

    /** @var list<int> Seconds the API asked to sleep for. */
    protected array $slept = [];

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->factory = new Psr17Factory();
        $this->slept = [];
    }

    /** An APIAuth that is already logged in; queues the OAuth response it consumes on the shared client. */
    protected function loggedInAuth(string $authToken = 'tok-1'): APIAuth
    {
        $auth = new APIAuth(
            'oauth2:scope',
            new GpsOAuth($this->http, $this->factory, $this->factory, GoogleKey::android()),
        );
        $this->http->queueBody("Auth={$authToken}\n");
        $auth->load('user@example.com', 'master-token', 'dev123');
        $this->http->requests = [];

        return $auth;
    }

    protected function sleeper(): \Closure
    {
        return function (int $seconds): void {
            $this->slept[] = $seconds;
        };
    }

    /**
     * JSON body of the n-th recorded request.
     *
     * @return array<array-key, mixed>
     */
    protected function json(int $index = 0): array
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
