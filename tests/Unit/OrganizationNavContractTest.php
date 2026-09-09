<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Organization nav group must not appear for a platform user who has not
 * entered an agency.
 *
 * That gate is real, not cosmetic. `SetTenant` defaults such a user's tenant
 * to the platform agency itself, `Gate::before` grants a superuser every
 * ability, and `employees`' `agency_not_platform` trigger raises P0001 — so
 * following Employees → Add employee from the platform tenant ends in an
 * uncaught 500, and neither the tenant being set nor the permission check
 * stops it. What decides it is whether the agency they are in is a real one.
 * docs/design/08-interface.md §10 and .ai/rules/components.md both say only
 * what exists is rendered and that a nav item leading nowhere is worse than
 * an absent one.
 *
 * Nothing in the build would notice the gate being dropped: `tsc` is happy
 * either way and the group would simply start rendering. So this reads the
 * component from disk and checks it, the same approach
 * PermissionMatrixContractTest takes to the permission set — a front-end
 * invariant with a back-end consequence, locked from the suite that runs on
 * every commit.
 *
 * SetTenantTest covers the other half: that the shared `agency` prop this
 * gate reads actually reports `platform` truthfully in both cases.
 */
class OrganizationNavContractTest extends TestCase
{
    private const SIDEBAR_PATH = __DIR__.'/../../resources/js/components/app-sidebar.tsx';

    public function test_the_organization_group_is_gated_on_being_inside_a_real_agency(): void
    {
        $source = $this->source();

        // The group exists at all — if the label ever changes, this test must
        // be updated deliberately rather than passing vacuously.
        $this->assertStringContainsString(
            "label: 'Organization'",
            $source,
            'resources/js/components/app-sidebar.tsx: expected an Organization group in the NavGroup[] array.',
        );

        $this->assertMatchesRegularExpression(
            '/agency\s*!==\s*null.*!agency\.platform/s',
            $this->beforeTheGroup($source),
            'resources/js/components/app-sidebar.tsx: the Organization group must be gated on an entered, non-platform agency. '
            .'A platform user sitting on the platform tenant cannot create a unit or an employee — agency_not_platform raises P0001 — '
            .'so the group must not be rendered for them.',
        );

        $this->assertMatchesRegularExpression(
            '/insideAgency\s*&&\s*can\(\s*\'organization\.view\'\s*\)/',
            $source,
            'resources/js/components/app-sidebar.tsx: the Organization group must also be gated on organization.view, '
            .'so a user without it is not offered two screens that answer 403.',
        );
    }

    /** Units and Employees, and nothing that has not landed yet (§10). */
    public function test_the_group_offers_only_the_screens_that_exist(): void
    {
        preg_match(
            "/label: 'Organization',\s*items: \[(.*?)\],/s",
            $this->source(),
            $matches,
        );

        $this->assertNotEmpty($matches, 'resources/js/components/app-sidebar.tsx: could not read the Organization group\'s items.');

        preg_match_all("/title: '([^']+)'/", $matches[1], $titles);

        $this->assertSame(['Units', 'Employees'], $titles[1]);
    }

    private function source(): string
    {
        $source = file_get_contents(self::SIDEBAR_PATH);

        $this->assertNotFalse($source, 'resources/js/components/app-sidebar.tsx could not be read.');

        return $source;
    }

    /**
     * Everything up to the group's own literal, so the gate has to be the one
     * standing in front of it rather than any `agency.platform` test that
     * happens to appear later in the file.
     */
    private function beforeTheGroup(string $source): string
    {
        $at = strpos($source, "label: 'Organization'");

        $this->assertNotFalse($at);

        return substr($source, 0, $at);
    }
}
