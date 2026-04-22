<?php

namespace Tests\Unit;

use App\Services\BulkSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function tests for the oracle-table build helpers. Schema-touching
 * tests live in BulkSyncOracleTableTest (Feature).
 */
class ScryfallOracleBuildTest extends TestCase
{
    #[DataProvider('colorBitsProvider')]
    public function test_build_color_bits(?array $letters, int $expected): void
    {
        $this->assertSame($expected, BulkSyncService::buildColorBits($letters));
    }

    /**
     * @return array<string, array{0: array<int, string>|null, 1: int}>
     */
    public static function colorBitsProvider(): array
    {
        return [
            'null'            => [null, 0],
            'empty'           => [[], 0],
            'W only'          => [['W'], 1],
            'U only'          => [['U'], 2],
            'B only'          => [['B'], 4],
            'R only'          => [['R'], 8],
            'G only'          => [['G'], 16],
            'WUBRG full'      => [['W', 'U', 'B', 'R', 'G'], 31],
            'GW (Selesnya)'   => [['G', 'W'], 17],
            'UR (Izzet)'      => [['U', 'R'], 10],
            'WUB (Esper)'     => [['W', 'U', 'B'], 7],
            'lowercase'       => [['w', 'u'], 3],
            'dedupes'         => [['W', 'W', 'W'], 1],
            'unknown letter'  => [['X', 'W'], 1],
            'unknown only'    => [['X', 'Z'], 0],
        ];
    }

    /**
     * The subset predicate used by the `commander:` / `c<=` / `ci<=`
     * operators: an oracle matches when `(bits & ~target) = 0`. Exercise
     * the boundary cases here — colourless passes every target, colours
     * outside the target are rejected.
     *
     * @param  array<int, string>  $oracle
     * @param  array<int, string>  $target
     */
    #[DataProvider('subsetProvider')]
    public function test_subset_predicate(array $oracle, array $target, bool $expected): void
    {
        $oracleBits = BulkSyncService::buildColorBits($oracle);
        $targetBits = BulkSyncService::buildColorBits($target);
        $complement = ~$targetBits & 0b11111;
        $match = ($oracleBits & $complement) === 0;
        $this->assertSame($expected, $match);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: bool}>
     */
    public static function subsetProvider(): array
    {
        return [
            'colorless in GW commander'       => [[], ['G', 'W'], true],
            'GW oracle in GW commander'       => [['G', 'W'], ['G', 'W'], true],
            'G oracle in GW commander'        => [['G'], ['G', 'W'], true],
            'UG oracle NOT in GW commander'   => [['U', 'G'], ['G', 'W'], false],
            'UGW oracle NOT in GW commander'  => [['U', 'G', 'W'], ['G', 'W'], false],
            'R oracle NOT in GW commander'    => [['R'], ['G', 'W'], false],
            'any oracle in WUBRG commander'   => [['U', 'R'], ['W', 'U', 'B', 'R', 'G'], true],
        ];
    }
}
