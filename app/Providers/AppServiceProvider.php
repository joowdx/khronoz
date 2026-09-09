<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Code;
use App\Models\Device;
use App\Models\Refresh;
use App\Models\Secret;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Head\Enums\OgType;
use Laravel\Head\Facades\Head;
use Laravel\Head\HeadBuilder;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is a development tool only. It is a dev dependency and is
        // excluded from package discovery, so it is registered by hand here
        // and never exists in any other environment.
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDatabase();
        $this->configureTokens();
        $this->configureHead();
        $this->configureInertia();
        $this->configureAuthorization();
    }

    /**
     * Fail loudly in development and refuse destructive commands in production.
     */
    protected function configureDatabase(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Point Sanctum and Passport at this application's own models.
     */
    protected function configureTokens(): void
    {
        Sanctum::usePersonalAccessTokenModel(Secret::class);

        Passport::useClientModel(Client::class);
        Passport::useTokenModel(Token::class);
        Passport::useRefreshTokenModel(Refresh::class);
        Passport::useAuthCodeModel(Code::class);
        Passport::useDeviceCodeModel(Device::class);
    }

    /**
     * Server-side rendering is off everywhere by default and opted into per
     * route, because it only earns its cost on pages a crawler or a link
     * preview will fetch. Mark those with:
     *
     *     Route::get('/', ...)->metadata(['ssr' => true]);
     *     Route::metadata(['ssr' => true])->group(...);   // a whole section
     *
     * Route metadata survives route:cache, so this costs nothing per request
     * beyond an array lookup. Everything unmarked renders on the client.
     */
    protected function configureInertia(): void
    {
        Inertia::disableSsr(fn (Request $request) => ! $request->route()?->getMetadata('ssr', false));
    }

    /**
     * Site-wide document head defaults. These are the lowest priority layer:
     * routes and controllers override them field by field.
     */
    protected function configureHead(): void
    {
        Head::defaults(fn (HeadBuilder $head) => $head
            ->title(config('app.name'), suffix: ' — '.config('app.name'))
            ->canonical()
            ->viewport('width=device-width, initial-scale=1')
            ->colorScheme('light dark')
            ->og(siteName: config('app.name'), type: OgType::Website)
            ->searchableByRobots());
    }

    /**
     * One gate ability per Permission, named by its value (e.g. `users.manage`)
     * so `$user->can('users.manage')` works. Gate::before short-circuits every
     * check for a platform user without granting them permissions they do not
     * hold in the column — superuser is the platform flag, not a permission.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user) => $user->isPlatform() ? true : null);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->allows($permission));
        }
    }
}
