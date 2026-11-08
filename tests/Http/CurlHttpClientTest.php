<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Http;

use Gkeepapi\Http\CurlHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    private function client(): CurlHttpClient
    {
        $factory = new Psr17Factory();

        return new CurlHttpClient($factory, $factory);
    }

    public function testRequestsUseHttp11WithoutAlpnAndDoNotFollowRedirects(): void
    {
        $options = $this->client()->buildOptions(new Request('GET', 'https://example.test/x'));

        self::assertSame(CURL_HTTP_VERSION_1_1, $options[CURLOPT_HTTP_VERSION]);
        self::assertFalse($options[CURLOPT_SSL_ENABLE_ALPN]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame('https://example.test/x', $options[CURLOPT_URL]);
        self::assertSame('GET', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
    }

    public function testBodyAndHeadersAreForwarded(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/x',
            ['Content-Type' => 'application/json', 'Authorization' => 'OAuth t'],
            '{"a":1}',
        );

        $options = $this->client()->buildOptions($request);

        self::assertSame('{"a":1}', $options[CURLOPT_POSTFIELDS]);
        $headers = $options[CURLOPT_HTTPHEADER];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertContains('Authorization: OAuth t', $headers);
    }

    public function testExplicitAcceptEncodingIsNotOverridden(): void
    {
        $identity = $this->client()->buildOptions(
            new Request('POST', 'https://example.test/x', ['Accept-Encoding' => 'identity']),
        );
        $default = $this->client()->buildOptions(new Request('GET', 'https://example.test/x'));

        self::assertArrayNotHasKey(CURLOPT_ENCODING, $identity);
        self::assertSame('', $default[CURLOPT_ENCODING]);
    }

    public function testParseHeadersKeepsOnlyTheLastResponseBlock(): void
    {
        $headers = CurlHttpClient::parseHeaders([
            'HTTP/1.1 100 Continue',
            '',
            'HTTP/1.1 302 Found',
            'Location: https://lh3.example/m',
            'Set-Cookie: a=1',
            'Set-Cookie: b=2',
            '',
        ]);

        self::assertSame(
            ['Location' => ['https://lh3.example/m'], 'Set-Cookie' => ['a=1', 'b=2']],
            $headers,
        );
    }
}
