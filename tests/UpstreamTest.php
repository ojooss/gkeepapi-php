<?php

declare(strict_types=1);

namespace Gkeepapi\Tests;

use Gkeepapi\Upstream;
use PHPUnit\Framework\TestCase;

final class UpstreamTest extends TestCase
{
    public function testUpstreamMetadataIsDocumentedInUpstreamMd(): void
    {
        $doc = file_get_contents(__DIR__ . '/../UPSTREAM.md');

        self::assertIsString($doc);
        self::assertStringContainsString(Upstream::VERSION, $doc);
        self::assertStringContainsString(Upstream::COMMIT, $doc);
    }
}
