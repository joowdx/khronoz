<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsureAgencyTest extends TestCase
{
    /**
     * Every organization route a platform user can reach *without* a bound
     * model. Those are the falsifiable ones: `units.edit`, `employees.show`
     * and the rest bind a {model} that AgencyScope already refuses across
     * tenants, so they answer 404 whether this middleware runs or not and
     * could not detect it being dropped (.ai/rules/middleware.md's rule —
     * assert against the shape that can actually flip).
     *
     * MEASURED before the fix: `GET /employees/create` answered 200 and the
     * form rendered and filled in; `POST /employees` answered 500, because
     * `employees` carries an `agency_not_platform` trigger and P0001 arrived
     * uncaught. The sidebar hid the nav group, which is why nobody found it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function organizationRouteCases(): array
    {
        return [
            'employees.index' => ['get', 'employees.index'],
            'employees.create' => ['get', 'employees.create'],
            'employees.store' => ['post', 'employees.store'],
            'units.index' => ['get', 'units.index'],
            'units.create' => ['get', 'units.create'],
            'units.store' => ['post', 'units.store'],
        ];
    }

    #[DataProvider('organizationRouteCases')]
    public function test_the_platform_tenant_has_no_organization_screens(string $verb, string $routeName): void
    {
        // No agency entered, so SetTenant defaults this superuser's tenant to
        // the platform agency itself — the state the whole finding is about.
        $this->actingAsPlatform();

        $this->{$verb}(route($routeName))->assertNotFound();
    }

    /**
     * The other half, and what keeps the test above from passing vacuously:
     * the same superuser, one `enter` away, gets both screens. Without this a
     * middleware that 404s everything would look correct.
     */
    #[DataProvider('organizationRouteCases')]
    public function test_the_same_routes_answer_inside_a_real_agency(string $verb, string $routeName): void
    {
        // Scout's NullEngine returns nothing for every search, totals
        // included, so employees.index needs a real driver (.ai/rules/tests.md).
        config(['scout.driver' => 'database']);

        $this->actingAsPlatform(Agency::factory()->create());

        $response = $this->{$verb}(route($routeName));

        $verb === 'get'
            ? $response->assertOk()->assertInertia(fn (Assert $page) => $page->component(str_replace('.', '/', $routeName), false))
            // A store with no payload: what matters is that it reached
            // validation rather than the middleware's 404, so the assertion
            // is that the request was validated at all, not which field
            // complained (units and employees require different ones).
            : $response->assertRedirect()->assertSessionHasErrors();
    }
}
