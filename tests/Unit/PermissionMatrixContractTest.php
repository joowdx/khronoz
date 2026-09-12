<?php

namespace Tests\Unit;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

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

    /** @return array<int, string> */
    private function parseOfferedPermissions(): array
    {
        $source = file_get_contents(self::MATRIX_PATH);

        $this->assertNotFalse($source, 'resources/js/components/permission-matrix.tsx could not be read.');

        preg_match_all("/'([a-z]+\.[a-z]+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
