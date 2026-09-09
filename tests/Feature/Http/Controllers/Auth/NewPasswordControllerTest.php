<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewPasswordControllerTest extends TestCase
{
    public function test_reset_page_renders_with_the_token_and_email(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/reset-password')
                ->where('token', $token)
                ->where('email', $user->email));
    }

    public function test_valid_token_updates_the_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect(route('login'))->assertSessionHas('status', 'Your password has been reset.');

        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));
    }

    public function test_invalid_token_returns_an_error(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;

        $this->post(route('password.store'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);

        $this->assertSame($originalPassword, $user->fresh()->password);
    }
}
