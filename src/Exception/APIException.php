<?php

declare(strict_types=1);

namespace Gkeepapi\Exception;

/**
 * The API server returned an error.
 *
 * Port of gkeepapi/exception.py (MIT, (c) Kai Zhong / kiwiz).
 * The upstream `code` attribute is available via {@see self::getCode()}.
 */
class APIException extends \Exception
{
    /**
     * @param array<array-key, mixed> $error The decoded `error` object of the response, if any.
     */
    public function __construct(int $code, string $msg, public readonly array $error = [])
    {
        parent::__construct($msg, $code);
    }
}
