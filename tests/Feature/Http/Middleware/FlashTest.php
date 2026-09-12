<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use Inertia\Inertia;
use Tests\TestCase;

class FlashTest extends TestCase
{
    public function test_agency_switch_message_is_delivered_once_and_a_later_switch_can_repeat_it(): void
    {
        $agency = Agency::factory()->create(['name' => 'Records Office']);
        $this->actingAsPlatform();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post(route('platform.agencies.enter', $agency))->assertRedirect(route('dashboard'));

            $this->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
                ->assertOk()
                ->assertJsonPath('flash.success', 'Entered Records Office')
                ->assertJsonMissingPath('props.flash');

            $this->get(route('home'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
                ->assertOk()->assertJsonMissingPath('flash')->assertJsonMissingPath('props.flash');

            $this->get(route('dashboard'), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'dashboard',
                'X-Inertia-Partial-Data' => 'agency',
            ])->assertOk()->assertJsonMissingPath('flash')->assertJsonMissingPath('props.flash');
        }
    }

    public function test_errors_use_native_flash_and_are_consumed_on_a_partial_response(): void
    {
        $this->get(route('home'))->assertOk();

        $this->withSession(['error' => 'Unable to save.'])
            ->get(route('home'), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'home',
                'X-Inertia-Partial-Data' => 'demo',
            ])->assertOk()->assertJsonPath('flash.error', 'Unable to save.')
            ->assertJsonMissingPath('props.flash');

        $this->get(route('home'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->assertJsonMissingPath('flash');
    }
}
