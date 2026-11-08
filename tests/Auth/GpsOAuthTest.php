<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Auth;

use Gkeepapi\Auth\GoogleKey;
use Gkeepapi\Auth\GpsOAuth;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Tests\Http\RecordingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class GpsOAuthTest extends TestCase
{
    private RecordingHttpClient $http;
    private GpsOAuth $auth;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $factory = new Psr17Factory();
        $this->auth = new GpsOAuth($this->http, $factory, $factory, GoogleKey::android());
    }

    public function testParseAuthResponseSplitsOnFirstEqualsSign(): void
    {
        $parsed = GpsOAuth::parseAuthResponse("Auth=abc=def\nEmail=a@example.com\n\nRopText= \nBare\n");

        self::assertSame(
            ['Auth' => 'abc=def', 'Email' => 'a@example.com', 'RopText' => ' ', 'Bare' => ''],
            $parsed,
        );
    }

    public function testMasterLoginPostsAndroidFormToAuthUrl(): void
    {
        $this->http->queueBody("Token=aas_et/FAKE\nEmail=user@example.com\n");

        $res = $this->auth->performMasterLogin('user@example.com', 'secret', 'dev123');

        self::assertSame(['Token' => 'aas_et/FAKE', 'Email' => 'user@example.com'], $res);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://android.clients.google.com/auth', (string) $request->getUri());
        self::assertSame('GoogleAuth/1.4', $request->getHeaderLine('User-Agent'));
        self::assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $form = $this->http->form();
        self::assertSame('HOSTED_OR_GOOGLE', $form['accountType']);
        self::assertSame('user@example.com', $form['Email']);
        self::assertSame('1', $form['has_permission']);
        self::assertSame('1', $form['add_account']);
        self::assertSame('ac2dm', $form['service']);
        self::assertSame('android', $form['source']);
        self::assertSame('dev123', $form['androidId']);
        self::assertSame('us', $form['device_country']);
        self::assertSame('us', $form['operatorCountry']);
        self::assertSame('en', $form['lang']);
        self::assertSame('17', $form['sdk_version']);
        self::assertSame('240913000', $form['google_play_services_version']);
        self::assertSame(GpsOAuth::DEFAULT_CLIENT_SIG, $form['client_sig']);
        self::assertSame(GpsOAuth::DEFAULT_CLIENT_SIG, $form['callerSig']);
        self::assertSame('dummy123', $form['droidguard_results']);
        self::assertArrayHasKey('EncryptedPasswd', $form);
        self::assertStringNotContainsString('secret', (string) $request->getBody());
    }

    public function testExchangeTokenSendsAccessTokenFlag(): void
    {
        $this->http->queueBody("Token=master\n");

        $this->auth->exchangeToken('user@example.com', 'oauth2_4/FAKE', 'dev123');

        $form = $this->http->form();
        self::assertSame('1', $form['ACCESS_TOKEN']);
        self::assertSame('oauth2_4/FAKE', $form['Token']);
        self::assertSame('1', $form['add_account']);
        self::assertArrayNotHasKey('EncryptedPasswd', $form);
    }

    public function testPerformOAuthSendsMasterTokenAsEncryptedPasswd(): void
    {
        $this->http->queueBody("Auth=token\n");

        $res = $this->auth->performOAuth(
            'user@example.com',
            'aas_et/FAKE',
            'dev123',
            'oauth2:scope',
            'com.example.app',
            'sig',
        );

        self::assertSame(['Auth' => 'token'], $res);
        $form = $this->http->form();
        self::assertSame('aas_et/FAKE', $form['EncryptedPasswd']);
        self::assertSame('oauth2:scope', $form['service']);
        self::assertSame('com.example.app', $form['app']);
        self::assertSame('sig', $form['client_sig']);
        self::assertArrayNotHasKey('add_account', $form);
        self::assertArrayNotHasKey('callerSig', $form);
    }

    public function testErrorResponsesAreParsedRegardlessOfStatus(): void
    {
        $this->http->queueBody("Error=BadAuthentication\n", 403);

        $res = $this->auth->performOAuth('u@example.com', 't', 'd', 's', 'a', 'c');

        self::assertSame(['Error' => 'BadAuthentication'], $res);
    }

    public function testTransportFailureBecomesLoginExceptionWithoutLeakingRequestData(): void
    {
        $failure = new class ('connection to secret-host failed') extends \RuntimeException implements
            ClientExceptionInterface
        {
        };
        $this->http->queueFailure($failure);

        try {
            $this->auth->performMasterLogin('user@example.com', 'secret', 'dev123');
            self::fail('Expected LoginException');
        } catch (LoginException $e) {
            self::assertSame('Auth request failed', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }
    }
}
