<?php

declare(strict_types=1);

namespace Gkeepapi\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal PSR-18 client on ext-curl (optional; any PSR-18 client works if it meets the requirements below).
 *
 * It exists because Google's Android login endpoint expects the TLS handshake gpsoauth produces: HTTP/1.1 and no
 * ALPN (Google answers "403 Bad Authentication" otherwise). Redirects are never followed, as
 * {@see \Gkeepapi\Api\MediaAPI::get()} reads the `Location` header.
 */
final readonly class CurlHttpClient implements ClientInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private int $timeout = 30,
    ) {
    }

    /**
     * The cURL options used for a request.
     *
     * @return array<int, mixed>
     */
    public function buildOptions(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ': ' . implode(', ', $values);
        }

        $options = [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_ENABLE_ALPN => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        $body = (string) $request->getBody();
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!$request->hasHeader('Accept-Encoding')) {
            $options[CURLOPT_ENCODING] = '';
        }

        return $options;
    }

    /**
     * Parse raw response header lines.
     *
     * @param list<string> $lines
     * @return array<string, list<string>> Header name => values (the last response block wins).
     */
    public static function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                $headers[trim($parts[0])][] = trim($parts[1]);
            }
        }

        return $headers;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();
        $headerLines = [];
        curl_setopt_array($handle, $this->buildOptions($request) + [
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerLines): int {
                $headerLines[] = rtrim($line, "\r\n");

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if ($body === false || curl_errno($handle) !== 0) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new CurlClientException('HTTP request failed: ' . $error);
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $response = $this->responseFactory->createResponse($status)
            ->withBody($this->streamFactory->createStream(is_string($body) ? $body : ''));
        foreach (self::parseHeaders($headerLines) as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }
}
