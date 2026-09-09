<?php

namespace Tests\Unit;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * The permission matrix (resources/js/components/permission-matrix.tsx) is
 * the only place a permission is offered to a person, and it writes its rows
 * out by hand: the row label is the sentence a reader sees ("Units,
 * employees, deployments and tags"), not a name the enum holds, so it
 * cannot be derived from `Permission::cases()`.
 *
 * That makes the matrix a fourth copy of the permission set, alongside the
 * enum, the `Permission` union in types/index.d.ts and the `implied` map in
 * use-can.ts (all three held together by PermissionContractTest). A new case
 * added to the enum but not to the matrix would be a right nobody can ever
 * grant, and nothing else in the build would notice — so this reads the
 * component from disk and checks it, exactly as that test does.
 *
 * A regex that matched nothing would compare two empty lists and pass
 * vacuously, hiding the drift it exists to catch, so the parsed count is
 * asserted against the enum's before the sets are compared.
 */
class PermissionMatrixContractTest extends TestCase
{
    private const MATRIX_PATH = __DIR__.'/../../resources/js/components/permission-matrix.tsx';

    public function test_every_permission_is_reachable_in_the_matrix(): void
    {
        $expected = array_map(fn (Permission $permission) => $permission->value, Permission::cases());

        $offered = $this->parseOfferedPermissions();

        $this->assertCount(
            count($expected),
            $offered,
            sprintf(
                'resources/js/components/permission-matrix.tsx: expected %d permission strings (one per Permission::cases()), parsed %d. A new case needs a row in AREAS, or its own control beside ATTEST.',
                count($expected),
                count($offered),
            )
        );

        $this->assertEqualsCanonicalizing($expected, $offered);
    }

    /**
     * Every `'<area>.<right>'` literal in the file: the `view` and `manage`
     * of each AREAS row, plus ATTEST's own. Matching the shape of a
     * permission value rather than a variable name keeps this indifferent to
     * how the rows are formatted.
     *
     * @return array<int, string>
     */
    private function parseOfferedPermissions(): array
    {
        $source = file_get_contents(self::MATRIX_PATH);

        $this->assertNotFalse($source, 'resources/js/components/permission-matrix.tsx could not be read.');

        preg_match_all("/'([a-z]+\.[a-z]+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
