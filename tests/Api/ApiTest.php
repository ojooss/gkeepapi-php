<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Api;

use Gkeepapi\Api\API;
use Gkeepapi\Exception\APIException;
use Gkeepapi\Exception\LoginException;
use Psr\Http\Client\ClientExceptionInterface;

final class ApiTest extends ApiTestCase
{
    private function api(bool $auth = true): API
    {
        return new API(
            'https://api.example/',
            $this->http,
            $this->factory,
            $this->factory,
            $auth ? $this->loggedInAuth() : null,
            $this->sleeper(),
        );
    }

    public function testSendSignsRequestsWithTheOauthToken(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"ok": true}');

        $res = $api->send('https://api.example/x', 'POST', ['a' => 1]);

        self::assertSame(['ok' => true], $res);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example/x', (string) $request->getUri());
        self::assertSame('OAuth tok-1', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(API::USER_AGENT, $request->getHeaderLine('User-Agent'));
        self::assertSame(['a' => 1], $this->json());
    }

    public function testGetRequestsCarryNoBody(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->send('https://api.example/x');

        self::assertSame('GET', $this->http->requests[0]->getMethod());
        self::assertSame('', (string) $this->http->requests[0]->getBody());
        self::assertFalse($this->http->requests[0]->hasHeader('Content-Type'));
    }

    public function testEmptyObjectBodyStaysAnObject(): void
    {
        $api = $this->api();
        $this->http->queueBody('{}');

        $api->send('https://api.example/x', 'POST', new \stdClass());

        self::assertSame('{}', (string) $this->http->requests[0]->getBody());
    }

    public function testSendRequiresAuth(): void
    {
        $this->expectException(LoginException::class);

        $this->api(false)->send('https://api.example/x');
    }

    public function testSendRequiresALoggedInSession(): void
    {
        $auth = $this->loggedInAuth();
        $auth->logout();
        $api = new API('https://api.example/', $this->http, $this->factory, $this->factory, $auth);

        try {
            $api->send('https://api.example/x');
            self::fail('Expected LoginException');
        } catch (LoginException $e) {
            self::assertSame('Not logged in', $e->getMessage());
            self::assertSame([], $this->http->requests);
        }
    }

    public function testExpiredTokenIsRefreshedAndRequestRepeated(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"error": {"code": 401, "message": "expired"}}')
            ->queueBody("Auth=tok-2\n")
            ->queueBody('{"ok": true}');

        $res = $api->send('https://api.example/x', 'POST', ['a' => 1]);

        self::assertSame(['ok' => true], $res);
        self::assertCount(3, $this->http->requests);
        self::assertSame('OAuth tok-1', $this->http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('https://android.clients.google.com/auth', (string) $this->http->requests[1]->getUri());
        self::assertSame('OAuth tok-2', $this->http->requests[2]->getHeaderLine('Authorization'));
        self::assertSame(['a' => 1], $this->json(2));
    }

    public function testGivesUpAfterTwoTokenRefreshes(): void
    {
        $api = $this->api();
        $unauthorized = '{"error": {"code": 401, "message": "expired"}}';
        $this->http->queueBody($unauthorized)->queueBody("Auth=tok-2\n")
            ->queueBody($unauthorized)->queueBody("Auth=tok-3\n")
            ->queueBody($unauthorized);

        try {
            $api->send('https://api.example/x');
            self::fail('Expected APIException');
        } catch (APIException $e) {
            self::assertSame(401, $e->getCode());
            self::assertSame('expired', $e->getMessage());
            self::assertSame(401, $e->error['code']);
        }
        self::assertCount(5, $this->http->requests);
    }

    public function testOtherErrorsAreRaisedImmediately(): void
    {
        $api = $this->api();
        $this->http->queueBody('{"error": {"code": 500, "message": "boom"}}');

        try {
            $api->send('https://api.example/x');
            self::fail('Expected APIException');
        } catch (APIException $e) {
            self::assertSame(500, $e->getCode());
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testRateLimitingBacksOffExponentiallyUpTo60Seconds(): void
    {
        $api = $this->api();
        $limited = '{"error": {"code": 429}}';
        for ($i = 0; $i < 7; $i++) {
            $this->http->queueBody($limited);
        }
        $this->http->queueBody('{"ok": true}');

        self::assertSame(['ok' => true], $api->send('https://api.example/x'));

        self::assertSame([2, 4, 8, 16, 32, 60, 60], $this->slept);
    }

    public function testRateLimitRetriesAreBounded(): void
    {
        $api = $this->api();
        for ($i = 0; $i <= API::RATE_LIMIT_RETRIES; $i++) {
            $this->http->queueBody('{"error": {"code": 429}}');
        }

        try {
            $api->send('https://api.example/x');
            self::fail('Expected APIException');
        } catch (APIException $e) {
            self::assertSame(429, $e->getCode());
        }
        self::assertCount(API::RATE_LIMIT_RETRIES, $this->slept);
    }

    public function testNonJsonResponsesAreReportedAsApiException(): void
    {
        $api = $this->api();
        $this->http->queueBody('<html>Bad Gateway</html>', 502);

        try {
            $api->send('https://api.example/x');
            self::fail('Expected APIException');
        } catch (APIException $e) {
            self::assertSame(502, $e->getCode());
            self::assertStringNotContainsString('Bad Gateway', $e->getMessage());
        }
    }

    public function testTransportFailuresDoNotLeakTheToken(): void
    {
        $api = $this->api();
        $failure = new class ('failed for Authorization: OAuth tok-1') extends \RuntimeException implements
            ClientExceptionInterface
        {
        };
        $this->http->queueFailure($failure);

        try {
            $api->send('https://api.example/x');
            self::fail('Expected LoginException');
        } catch (LoginException $e) {
            self::assertStringNotContainsString('tok-1', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testAuthCanBeReplaced(): void
    {
        $api = $this->api(false);
        self::assertNull($api->getAuth());

        $auth = $this->loggedInAuth('tok-9');
        $api->setAuth($auth);
        $this->http->queueBody('{}');
        $api->send('https://api.example/x');

        self::assertSame($auth, $api->getAuth());
        self::assertSame('OAuth tok-9', $this->http->requests[0]->getHeaderLine('Authorization'));
    }
}
