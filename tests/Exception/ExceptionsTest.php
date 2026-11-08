<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Exception;

use Gkeepapi\Exception\APIException;
use Gkeepapi\Exception\BrowserLoginRequiredException;
use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Exception\KeepException;
use Gkeepapi\Exception\LabelException;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Exception\MergeException;
use Gkeepapi\Exception\ParseException;
use Gkeepapi\Exception\ResyncRequiredException;
use Gkeepapi\Exception\SyncException;
use Gkeepapi\Exception\UpgradeRecommendedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    /** @return iterable<string, array{class-string<\Throwable>, class-string<\Throwable>}> */
    public static function hierarchy(): iterable
    {
        yield 'Login' => [LoginException::class, KeepException::class];
        yield 'BrowserLoginRequired' => [BrowserLoginRequiredException::class, LoginException::class];
        yield 'Label' => [LabelException::class, KeepException::class];
        yield 'Sync' => [SyncException::class, KeepException::class];
        yield 'ResyncRequired' => [ResyncRequiredException::class, SyncException::class];
        yield 'UpgradeRecommended' => [UpgradeRecommendedException::class, SyncException::class];
        yield 'Merge' => [MergeException::class, KeepException::class];
        yield 'Invalid' => [InvalidException::class, KeepException::class];
        yield 'Parse' => [ParseException::class, KeepException::class];
        yield 'Keep' => [KeepException::class, \Exception::class];
        yield 'API' => [APIException::class, \Exception::class];
    }

    /**
     * @param class-string<\Throwable> $class
     * @param class-string<\Throwable> $parent
     */
    #[DataProvider('hierarchy')]
    public function testHierarchyMatchesUpstream(string $class, string $parent): void
    {
        self::assertTrue(is_subclass_of($class, $parent));
    }

    public function testApiExceptionExposesCodeAndMessage(): void
    {
        $e = new APIException(401, 'unauthorized');

        self::assertSame(401, $e->getCode());
        self::assertSame('unauthorized', $e->getMessage());
    }

    public function testBrowserLoginRequiredExposesUrl(): void
    {
        $e = new BrowserLoginRequiredException('https://example.test/login');

        self::assertSame('https://example.test/login', $e->url);
    }

    public function testParseExceptionExposesRawPayload(): void
    {
        $e = new ParseException('bad node', ['id' => 'x']);

        self::assertSame('bad node', $e->getMessage());
        self::assertSame(['id' => 'x'], $e->raw);
    }
}
