<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * employees/index.tsx and units/index.tsx each carry a `COLUMNS` constant
 * that both the `TableHead` widths and the table's `min-w` floor
 * (`TABLE_MIN_WIDTH`) read, so the floor cannot silently drift out of step
 * with the columns the way a hand-typed `min-w-[1040px]` / `min-w-[1060px]`
 * did (fix round 2 review): the floor had been derived as "798 declared +
 * 242 flexible" as if editing a `TableHead`'s width kept the comment's sum
 * honest on its own. Nothing in `tsc` or the bundle would notice a
 * `TableHead` reverting to a hard-coded `w-[…px]`, or the floor being
 * hand-typed again instead of computed from `COLUMNS` — so this reads both
 * page files from disk and checks them, the same read-the-source approach
 * `PermissionMatrixContractTest` and `EmployeeControllerTest`'s `PARTIAL`
 * check take.
 *
 * This does not re-verify the *rendered* pixel widths — `table-layout: auto`
 * only ever treats a `TableHead`'s width as a preference, so that needs a
 * browser, not a regex. That is measured instead and recorded in
 * `.ai/rules/pages.md` and in the `COLUMNS` docblocks themselves.
 */
class TableColumnFloorContractTest extends TestCase
{
    public function test_employees_index_floor_is_derived_from_its_declared_columns(): void
    {
        $this->assertFloorIsDerivedFromColumns('employees/index.tsx', ['unit', 'position', 'tags', 'actions']);
    }

    public function test_units_index_floor_is_derived_from_its_declared_columns(): void
    {
        $this->assertFloorIsDerivedFromColumns('units/index.tsx', ['unit', 'kind', 'people', 'actions']);
    }

    /**
     * @param  array<int, string>  $expectedKeys  every column `COLUMNS` must declare, in the order its `TableHead`s render
     */
    private function assertFloorIsDerivedFromColumns(string $file, array $expectedKeys): void
    {
        $source = $this->source($file);

        $columns = $this->parseColumns($source, $file);

        $this->assertSame(
            $expectedKeys,
            array_keys($columns),
            "resources/js/pages/{$file}: COLUMNS must declare exactly ".implode(', ', $expectedKeys).', in that order — one entry per fixed-width column the TableHead row renders.',
        );

        foreach ($columns as $key => $width) {
            $this->assertGreaterThan(0, $width, "resources/js/pages/{$file}: COLUMNS.{$key} must be a positive pixel width.");

            // The single-source requirement itself: this exact column's
            // TableHead must read its width from COLUMNS, not repeat a
            // literal that COLUMNS could drift away from.
            $this->assertStringContainsString(
                "style={{ width: COLUMNS.{$key} }}",
                $source,
                "resources/js/pages/{$file}: the {$key} TableHead must read its width from COLUMNS.{$key}, not a hard-coded className.",
            );
        }

        // No fixed column left reading a hand-typed arbitrary-value class —
        // the exact shape the previous, undetected drift took.
        $this->assertDoesNotMatchRegularExpression(
            '/<TableHead className="w-\[\d+px\]"/',
            $source,
            "resources/js/pages/{$file}: a TableHead is using a hard-coded w-[…px] className instead of reading its width from COLUMNS.",
        );

        $this->assertMatchesRegularExpression(
            '/const FLEX_MIN = (\d+);/',
            $source,
            "resources/js/pages/{$file}: could not read FLEX_MIN — the allowance the one flexible column (with no entry in COLUMNS) needs.",
        );
        preg_match('/const FLEX_MIN = (\d+);/', $source, $flexMatch);
        $flexMin = (int) $flexMatch[1];

        $this->assertGreaterThan(0, $flexMin, "resources/js/pages/{$file}: FLEX_MIN must leave the flexible column some room, or the floor covers only the fixed columns.");

        // The floor itself must be COMPUTED from COLUMNS and FLEX_MIN, not a
        // hand-typed number repeating their sum — this is the actual defect
        // fix round 2 found: a `min-w-[1040px]` that had already drifted 4px
        // short of what its own comment claimed.
        $this->assertMatchesRegularExpression(
            '/const TABLE_MIN_WIDTH = Object\.values\(COLUMNS\)\.reduce\(.*, 0\)\s*\+\s*FLEX_MIN;/',
            $source,
            "resources/js/pages/{$file}: TABLE_MIN_WIDTH must be computed as Object.values(COLUMNS).reduce(...) + FLEX_MIN, not hard-coded, or the floor can silently fall out of step with the columns again.",
        );

        $this->assertStringContainsString(
            'style={{ minWidth: TABLE_MIN_WIDTH }}',
            $source,
            "resources/js/pages/{$file}: the <Table> must read its min-width from TABLE_MIN_WIDTH, not a hard-coded min-w-[…px] className.",
        );
    }

    /**
     * Every `key: number,` pair inside the `COLUMNS = { … } as const` object
     * literal, in source order — order matters here because it is compared
     * against the order the TableHead row renders them in.
     *
     * @return array<string, int>
     */
    private function parseColumns(string $source, string $file): array
    {
        $this->assertMatchesRegularExpression(
            '/const COLUMNS = \{(.*?)\} as const;/s',
            $source,
            "resources/js/pages/{$file}: could not read the COLUMNS constant.",
        );

        preg_match('/const COLUMNS = \{(.*?)\} as const;/s', $source, $block);

        preg_match_all('/(\w+):\s*(\d+),/', $block[1], $pairs);

        $this->assertNotEmpty($pairs[1], "resources/js/pages/{$file}: COLUMNS parsed with no entries.");

        return array_combine($pairs[1], array_map('intval', $pairs[2]));
    }

    private function source(string $file): string
    {
        $path = __DIR__."/../../resources/js/pages/{$file}";

        $source = file_get_contents($path);

        $this->assertNotFalse($source, "resources/js/pages/{$file} could not be read.");

        return $source;
    }
}
