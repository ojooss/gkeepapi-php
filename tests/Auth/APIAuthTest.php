<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Auth;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Auth\GoogleKey;
use Gkeepapi\Auth\GpsOAuth;
use Gkeepapi\Exception\BrowserLoginRequiredException;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Tests\Http\RecordingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class APIAuthTest extends TestCase
{
    private const SCOPES = 'oauth2:https://www.googleapis.com/auth/memento';

    private RecordingHttpClient $http;
    private APIAuth $auth;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $factory = new Psr17Factory();
        $this->auth = new APIAuth(
            self::SCOPES,
            new GpsOAuth($this->http, $factory, $factory, GoogleKey::android()),
        );
    }

    public function testLoginObtainsMasterTokenThenAuthToken(): void
    {
        $this->http->queueBody("Token=master-1\n")->queueBody("Auth=auth-1\n");

        $this->auth->login('user@example.com', 'secret', 'dev123');

        self::assertSame('master-1', $this->auth->getMasterToken());
        self::assertSame('auth-1', $this->auth->getAuthToken());
        self::assertSame('user@example.com', $this->auth->getEmail());
        self::assertSame('dev123', $this->auth->getDeviceId());
        self::assertCount(2, $this->http->requests);
        $oauth = $this->http->form(1);
        self::assertSame('master-1', $oauth['EncryptedPasswd']);
        self::assertSame(self::SCOPES, $oauth['service']);
        self::assertSame('com.google.android.keep', $oauth['app']);
        self::assertSame('38918a453d07199354f8b19af05ec6562ced5788', $oauth['client_sig']);
    }

    public function testLoginRequiresBrowserWhenGoogleSaysSo(): void
    {
        $this->http->queueBody("Error=NeedsBrowser\nUrl=https://accounts.example/continue\n");

        try {
            $this->auth->login('user@example.com', 'secret', 'dev123');
            self::fail('Expected BrowserLoginRequiredException');
        } catch (BrowserLoginRequiredException $e) {
            self::assertSame('https://accounts.example/continue', $e->url);
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testLoginFailsWithoutToken(): void
    {
        $this->http->queueBody("Error=BadAuthentication\nErrorDetail=Nope\n", 403);

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('BadAuthentication: Nope');

        $this->auth->login('user@example.com', 'secret', 'dev123');
    }

    public function testLoadUsesGivenMasterToken(): void
    {
        $this->http->queueBody("Auth=auth-2\n");

        self::assertTrue($this->auth->load('user@example.com', 'master-2', 'dev123'));

        self::assertSame('master-2', $this->auth->getMasterToken());
        self::assertSame('auth-2', $this->auth->getAuthToken());
        self::assertCount(1, $this->http->requests);
    }

    public function testRefreshFailsWithoutAuthToken(): void
    {
        $this->auth->setEmail('user@example.com');
        $this->auth->setMasterToken('master');
        $this->auth->setDeviceId('dev123');
        $this->http->queueBody("Error=BadAuthentication\n");

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('BadAuthentication');

        $this->auth->refresh();
    }

    public function testRefreshReturnsNewAuthToken(): void
    {
        $this->auth->setEmail('user@example.com');
        $this->auth->setMasterToken('master');
        $this->auth->setDeviceId('dev123');
        $this->http->queueBody("Auth=fresh\n");

        self::assertSame('fresh', $this->auth->refresh());
    }

    public function testRefreshWithoutCredentialsFailsBeforeAnyRequest(): void
    {
        try {
            $this->auth->refresh();
            self::fail('Expected LoginException');
        } catch (LoginException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testErrorMessagesNeverContainTokens(): void
    {
        $this->http->queueBody("Error=BadAuthentication\nToken=leaky\n");

        try {
            $this->auth->load('user@example.com', 'master-secret', 'dev123');
            self::fail('Expected LoginException');
        } catch (LoginException $e) {
            self::assertStringNotContainsString('master-secret', $e->getMessage());
            self::assertStringNotContainsString('leaky', $e->getMessage());
        }
    }

    public function testLogoutClearsAllState(): void
    {
        $this->http->queueBody("Auth=auth-3\n");
        $this->auth->load('user@example.com', 'master-3', 'dev123');

        $this->auth->logout();

        self::assertNull($this->auth->getMasterToken());
        self::assertNull($this->auth->getAuthToken());
        self::assertNull($this->auth->getEmail());
        self::assertNull($this->auth->getDeviceId());
    }
}
