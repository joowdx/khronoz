<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

class PermissionContractTest extends TestCase
{
    private const TYPES_PATH = __DIR__.'/../../../resources/js/types/index.d.ts';

    private const USE_CAN_PATH = __DIR__.'/../../../resources/js/hooks/use-can.ts';

    public function test_typescript_permission_union_matches_the_enum_values(): void
    {
        $expected = array_map(fn (Permission $permission) => $permission->value, Permission::cases());

        $values = $this->parseUnionValues();

        $this->assertCount(
            count($expected),
            $values,
            sprintf(
                'resources/js/types/index.d.ts: expected the `Permission` union to parse to %d entries (one per Permission::cases()), got %d — the file shape probably changed under the regex that parses it (PermissionContractTest::parseUnionValues).',
                count($expected),
                count($values),
            )
        );

        $this->assertEqualsCanonicalizing($expected, $values);
    }

    public function test_use_can_implied_map_matches_the_flattened_implies_edges(): void
    {
        $expected = [];

        foreach (Permission::cases() as $permission) {
            foreach ($permission->implies() as $implied) {
                $expected[$permission->value] = $implied->value;
            }
        }

        $edges = $this->parseImpliedMap();

        $this->assertCount(
            count($expected),
            $edges,
            sprintf(
                'resources/js/hooks/use-can.ts: expected the `implied` map to parse to %d entries (the flattened Permission::implies() edges), got %d — the file shape probably changed under the regex that parses it (PermissionContractTest::parseImpliedMap).',
                count($expected),
                count($edges),
            )
        );

        $this->assertSame($expected, $edges);
    }

    /** @return array<int, string> */
    private function parseUnionValues(): array
    {
        $contents = file_get_contents(self::TYPES_PATH);
        $this->assertNotFalse($contents, 'could not read '.self::TYPES_PATH);

        $found = preg_match('/export type Permission =(.*?);/s', $contents, $block);
        $this->assertSame(1, $found, 'could not find `export type Permission = ...;` in '.self::TYPES_PATH.' — the file shape changed.');

        preg_match_all("/'([^']+)'/", $block[1], $matches);

        return $matches[1];
    }

    /** @return array<string, string> */
    private function parseImpliedMap(): array
    {
        $contents = file_get_contents(self::USE_CAN_PATH);
        $this->assertNotFalse($contents, 'could not read '.self::USE_CAN_PATH);

        $found = preg_match('/export const implied:[^=]*=\s*\{(.*?)\};/s', $contents, $block);
        $this->assertSame(1, $found, 'could not find `export const implied: ... = { ... };` in '.self::USE_CAN_PATH.' — the file shape changed.');

        preg_match_all("/'([^']+)'\s*:\s*'([^']+)'/", $block[1], $pairs, PREG_SET_ORDER);

        $edges = [];
        foreach ($pairs as $pair) {
            $edges[$pair[1]] = $pair[2];
        }

        return $edges;
    }
}
