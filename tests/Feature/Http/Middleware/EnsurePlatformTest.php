<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsurePlatformTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
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
