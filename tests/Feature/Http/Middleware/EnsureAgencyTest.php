<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsureAgencyTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function organizationRouteCases(): array
    {
        return [
            'employees.index' => ['get', 'employees.index'],
            'employees.create' => ['get', 'employees.create'],
            'employees.store' => ['post', 'employees.store'],
            'workgroups.index' => ['get', 'workgroups.index'],
            'workgroups.create' => ['get', 'workgroups.create'],
            'workgroups.store' => ['post', 'workgroups.store'],
        ];
    }

    #[DataProvider('organizationRouteCases')]
    public function test_the_platform_tenant_has_no_organization_screens(string $verb, string $routeName): void
    {
        $this->actingAsPlatform();

        $this->{$verb}(route($routeName))->assertNotFound();
    }

    #[DataProvider('organizationRouteCases')]
    public function test_the_same_routes_answer_inside_a_real_agency(string $verb, string $routeName): void
    {
        config(['scout.driver' => 'database']);

        $this->actingAsPlatform(Agency::factory()->create());

        $response = $this->{$verb}(route($routeName));

        $verb === 'get'
            ? $response->assertOk()->assertInertia(fn (Assert $page) => $page->component(str_replace('.', '/', $routeName), false))
            : $response->assertRedirect()->assertSessionHasErrors();
    }
}
