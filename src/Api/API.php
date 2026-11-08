<?php

declare(strict_types=1);

namespace Gkeepapi\Api;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Exception\APIException;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Log;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Base API wrapper.
 *
 * Port of gkeepapi/__init__.py `API` (MIT, (c) Kai Zhong / kiwiz). The HTTP client is injected (PSR-18).
 */
class API
{
    public const RETRY_CNT = 2;

    /** Upper bound for consecutive "429 Too Many Requests" retries (upstream retries forever). */
    public const RATE_LIMIT_RETRIES = 10;

    public const USER_AGENT = 'x-gkeepapi-php (https://github.com/ojooss/gkeepapi-php)';

    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /**
     * @param \Closure(int): void|null $sleeper Called with the number of seconds to wait when rate limited;
     *        defaults to `sleep()`.
     */
    public function __construct(
        protected readonly string $baseUrl,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        protected ?APIAuth $auth = null,
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** Get authentication details for this API. */
    public function getAuth(): ?APIAuth
    {
        return $this->auth;
    }

    /** Set authentication details for this API. */
    public function setAuth(APIAuth $auth): void
    {
        $this->auth = $auth;
    }

    /**
     * Send an authenticated request to a Google API. Automatically retries if the access token has expired.
     *
     * @param string $url The full request URL.
     * @param string $method The HTTP method.
     * @param array<array-key, mixed>|\stdClass|null $json Body, sent as JSON; use `new \stdClass()` for `{}`.
     * @return array<array-key, mixed> The parsed JSON response.
     * @throws APIException If the server returns an error.
     * @throws LoginException If the session is not authenticated.
     */
    public function send(string $url, string $method = 'GET', array|\stdClass|null $json = null): array
    {
        // Send a request to the API servers, with retry handling. OAuth tokens are valid for several hours
        // (as of the upstream comment).
        $i = 0;
        $rateLimited = 0;
        $delay = 2;
        while (true) {
            // Send off the request. If there was no error, we're good.
            $response = $this->decode($this->sendRaw($url, $method, $json));
            if (!array_key_exists('error', $response)) {
                break;
            }

            // Otherwise, check if it was a non-401 response code. These aren't handled, so bail.
            $error = $response['error'];
            if (!is_array($error)) {
                throw new APIException(0, 'Unexpected error response');
            }
            $code = is_int($error['code'] ?? null) ? $error['code'] : 0;
            if ($code === 429 && $rateLimited < self::RATE_LIMIT_RETRIES) {
                Log::logger()->debug('Rate limited...');
                ($this->sleeper)($delay);
                $delay = min($delay * 2, 60);
                $rateLimited++;
                continue;
            }
            if ($code !== 401) {
                throw new APIException($code, $this->errorMessage($error), $error);
            }

            // If we've exceeded the retry limit, also bail.
            if ($i >= self::RETRY_CNT) {
                throw new APIException($code, $this->errorMessage($error), $error);
            }

            // Otherwise, try requesting a new OAuth token.
            Log::logger()->info('Refreshing access token');
            $this->requireAuth()->refresh();
            $i++;
        }

        return $response;
    }

    /**
     * Send an authenticated request to a Google API without interpreting the response.
     *
     * Redirects must not be followed by the injected client if the `Location` header is of interest.
     *
     * @param array<array-key, mixed>|\stdClass|null $json
     * @throws LoginException If the session is not authenticated or the request cannot be sent.
     */
    protected function sendRaw(string $url, string $method, array|\stdClass|null $json = null): ResponseInterface
    {
        // Bail if we don't have an OAuth token.
        $authToken = $this->requireAuth()->getAuthToken();
        if ($authToken === null) {
            throw new LoginException('Not logged in');
        }

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Authorization', 'OAuth ' . $authToken);
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The message may echo the request (including the token); do not pass it on.
            throw new LoginException('API request failed', 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     * @throws APIException If the body is not a JSON object or array.
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new APIException($response->getStatusCode(), 'Invalid JSON response');
        }
        if (!is_array($data)) {
            throw new APIException($response->getStatusCode(), 'Unexpected JSON response');
        }

        return $data;
    }

    /** @param array<array-key, mixed> $error */
    private function errorMessage(array $error): string
    {
        return is_string($error['message'] ?? null) ? $error['message'] : 'API error';
    }

    private function requireAuth(): APIAuth
    {
        return $this->auth ?? throw new LoginException('Not logged in');
    }
}
