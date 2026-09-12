<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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
            .'A platform user sitting on the platform tenant cannot create a workgroup or an employee — agency_not_platform raises P0001 — '
            .'so the group must not be rendered for them.',
        );

        $this->assertMatchesRegularExpression(
            '/insideAgency\s*&&\s*can\(\s*\'organization\.view\'\s*\)/',
            $source,
            'resources/js/components/app-sidebar.tsx: the Organization group must also be gated on organization.view, '
            .'so a user without it is not offered two screens that answer 403.',
        );
    }

    public function test_the_group_offers_only_the_screens_that_exist(): void
    {
        preg_match(
            "/label: 'Organization',\s*items: \[(.*?)\],/s",
            $this->source(),
            $matches,
        );

        $this->assertNotEmpty($matches, 'resources/js/components/app-sidebar.tsx: could not read the Organization group\'s items.');

        preg_match_all("/title: '([^']+)'/", $matches[1], $titles);

        $this->assertSame(['Workgroups', 'Employees'], $titles[1]);
    }

    private function source(): string
    {
        $source = file_get_contents(self::SIDEBAR_PATH);

        $this->assertNotFalse($source, 'resources/js/components/app-sidebar.tsx could not be read.');

        return $source;
    }

    private function beforeTheGroup(string $source): string
    {
        $at = strpos($source, "label: 'Organization'");

        $this->assertNotFalse($at);

        return substr($source, 0, $at);
    }
}
