<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsurePlatformTest extends TestCase
{
    /**
     * Every route inside the `platform` middleware group. Only `index`'s 403
     * was previously pinned directly (AgencyControllerTest); the other six
     * were protected only because they share the group, so moving one of
     * them out of it would have gone uncaught. `edit`, `update` and `enter`
     * bind {agency} — the test supplies a real, non-platform agency's id, so
     * a middleware-ordering regression (binding resolving before the
     * `platform` middleware runs) would surface as something other than this
     * test's own 403 rather than being silently absorbed by a 404.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function platformRouteCases(): array
    {
        return [
            'index' => ['get', 'platform.agencies.index', false],
            'create' => ['get', 'platform.agencies.create', false],
            'store' => ['post', 'platform.agencies.store', false],
            'edit' => ['get', 'platform.agencies.edit', true],
            'update' => ['put', 'platform.agencies.update', true],
            'enter' => ['post', 'platform.agencies.enter', true],
            'leave' => ['delete', 'platform.agencies.leave', false],
        ];
    }

    #[DataProvider('platformRouteCases')]
    public function test_non_platform_user_is_forbidden_from_every_platform_route(string $verb, string $routeName, bool $needsAgency): void
    {
        $agency = Agency::factory()->create();

        $url = $needsAgency ? route($routeName, $agency) : route($routeName);

        $this->actingAs(User::factory()->create())->{$verb}($url)->assertForbidden();
    }
}
