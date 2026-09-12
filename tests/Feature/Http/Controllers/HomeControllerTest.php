<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    public function test_the_home_page_renders_for_a_guest(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('home'));
    }

    public function test_the_home_route_opts_into_server_rendering(): void
    {
        $this->assertTrue(Route::getRoutes()->getByName('home')?->getMetadata('ssr'));
    }

    public function test_the_home_page_carries_a_title_and_description_for_search_engines(): void
    {
        $this->get(route('home'))
            ->assertSee('>Scheduling and daily time records — '.config('app.name').'</title>', false)
            ->assertSee('name="description" content="khronoz is scheduling, biometric timelogs and CS Form 48', false)
            ->assertSee('property="og:description"', false);
    }

    public function test_the_demo_action_is_a_mailto_built_from_the_configured_sender(): void
    {
        Config::set('mail.from.address', 'hr@agency.example.ph');

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'demo',
                'mailto:hr@agency.example.ph?subject=khronoz%20demo%20request',
            ));
    }
}
