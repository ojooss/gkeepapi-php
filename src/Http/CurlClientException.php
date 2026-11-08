<?php

declare(strict_types=1);

namespace Gkeepapi\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Transport failure of {@see CurlHttpClient}. The message carries the cURL error only, never request data.
 */
final class CurlClientException extends \RuntimeException implements ClientExceptionInterface
{
}
