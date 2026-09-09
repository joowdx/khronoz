<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use App\Enums\Preset;
use PHPUnit\Framework\TestCase;

class PermissionTest extends TestCase
{
    public function test_manage_grants_its_view(): void
    {
        $this->assertTrue(Permission::ManageScheduling->grants(Permission::ViewScheduling));
        $this->assertFalse(Permission::ViewScheduling->grants(Permission::ManageScheduling));
        $this->assertTrue(Permission::AttestLedgers->grants(Permission::AttestLedgers));
        $this->assertTrue(Permission::AttestLedgers->grants(Permission::ViewLedgers));
    }

    public function test_presets_only_contain_known_permissions(): void
    {
        foreach (Preset::cases() as $preset) {
            $this->assertNotEmpty($preset->permissions());
            $this->assertContainsOnlyInstancesOf(Permission::class, $preset->permissions());
        }
        $this->assertEqualsCanonicalizing(Permission::cases(), Preset::Admin->permissions());
    }
}
