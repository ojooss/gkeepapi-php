<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Node;

use Gkeepapi\Node\BlobType;
use Gkeepapi\Node\CategoryValue;
use Gkeepapi\Node\CheckedListItemsPolicyValue;
use Gkeepapi\Node\ColorValue;
use Gkeepapi\Node\GraveyardStateValue;
use Gkeepapi\Node\NewListItemPlacementValue;
use Gkeepapi\Node\NodeType;
use Gkeepapi\Node\RoleValue;
use Gkeepapi\Node\ShareRequestValue;
use Gkeepapi\Node\SuggestValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wire values must match upstream gkeepapi/node.py exactly.
 */
final class EnumsTest extends TestCase
{
    /** @return iterable<string, array{class-string<\BackedEnum>, array<string, string>}> */
    public static function enums(): iterable
    {
        yield 'BlobType' => [BlobType::class, [
            'Audio' => 'AUDIO',
            'Image' => 'IMAGE',
            'Drawing' => 'DRAWING',
        ]];
        yield 'CategoryValue' => [CategoryValue::class, [
            'Books' => 'BOOKS',
            'Food' => 'FOOD',
            'Movies' => 'MOVIES',
            'Music' => 'MUSIC',
            'Places' => 'PLACES',
            'Quotes' => 'QUOTES',
            'Travel' => 'TRAVEL',
            'TV' => 'TV',
        ]];
        yield 'CheckedListItemsPolicyValue' => [CheckedListItemsPolicyValue::class, [
            'Default' => 'DEFAULT',
            'Graveyard' => 'GRAVEYARD',
        ]];
        yield 'ColorValue' => [ColorValue::class, [
            'White' => 'DEFAULT',
            'Red' => 'RED',
            'Orange' => 'ORANGE',
            'Yellow' => 'YELLOW',
            'Green' => 'GREEN',
            'Teal' => 'TEAL',
            'Blue' => 'BLUE',
            'DarkBlue' => 'CERULEAN',
            'Purple' => 'PURPLE',
            'Pink' => 'PINK',
            'Brown' => 'BROWN',
            'Gray' => 'GRAY',
        ]];
        yield 'GraveyardStateValue' => [GraveyardStateValue::class, [
            'Expanded' => 'EXPANDED',
            'Collapsed' => 'COLLAPSED',
        ]];
        yield 'NewListItemPlacementValue' => [NewListItemPlacementValue::class, [
            'Top' => 'TOP',
            'Bottom' => 'BOTTOM',
        ]];
        yield 'NodeType' => [NodeType::class, [
            'Note' => 'NOTE',
            'List' => 'LIST',
            'ListItem' => 'LIST_ITEM',
            'Blob' => 'BLOB',
        ]];
        yield 'RoleValue' => [RoleValue::class, [
            'Owner' => 'O',
            'User' => 'W',
        ]];
        yield 'ShareRequestValue' => [ShareRequestValue::class, [
            'Add' => 'WR',
            'Remove' => 'RM',
        ]];
        yield 'SuggestValue' => [SuggestValue::class, [
            'GroceryItem' => 'GROCERY_ITEM',
        ]];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param array<string, string> $expected
     */
    #[DataProvider('enums')]
    public function testCasesMatchUpstreamWireValues(string $enum, array $expected): void
    {
        $actual = [];
        /** @var list<\BackedEnum> $cases */
        $cases = $enum::cases();
        foreach ($cases as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame($expected, $actual);
    }

    #[DataProvider('enums')]
    public function testFromWireValueRoundTrips(string $enum): void
    {
        /** @var list<\BackedEnum> $cases */
        $cases = $enum::cases();
        foreach ($cases as $case) {
            self::assertSame($case, $enum::from($case->value));
        }
    }
}
