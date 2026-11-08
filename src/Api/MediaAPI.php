<?php

declare(strict_types=1);

namespace Gkeepapi\Api;

use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Exception\APIException;
use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Node\Blob;
use Gkeepapi\Node\BlobType;
use Gkeepapi\Node\NodeDrawing;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Low level Google Media API client. Mimics the Android Google Keep app.
 *
 * Port of gkeepapi/__init__.py `MediaAPI` (MIT, (c) Kai Zhong / kiwiz).
 */
class MediaAPI extends API
{
    public const API_URL = 'https://keep.google.com/media/v2/';

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?APIAuth $auth = null,
        ?\Closure $sleeper = null,
    ) {
        parent::__construct(self::API_URL, $httpClient, $requestFactory, $streamFactory, $auth, $sleeper);
    }

    /**
     * Get the canonical link to a media blob.
     *
     * The injected HTTP client must not follow redirects: the link is the `Location` header of the response.
     *
     * @return string A link to the media.
     * @throws InvalidException If the blob is not attached to a synced note.
     * @throws APIException If the server does not answer with a redirect.
     */
    public function get(Blob $blob): string
    {
        $parentServerId = $blob->parent?->serverId;
        if ($parentServerId === null || $blob->serverId === null) {
            throw new InvalidException('Blob has not been synced');
        }

        $url = $this->baseUrl . $parentServerId . '/' . $blob->serverId;
        if ($blob->blob?->type === BlobType::Drawing && $blob->blob instanceof NodeDrawing) {
            $url .= '/' . $blob->blob->getDrawingInfo()?->drawingId;
        }

        $response = $this->sendRaw($url, 'GET');
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            throw new APIException($response->getStatusCode(), 'No media location in response');
        }

        return $location;
    }
}
