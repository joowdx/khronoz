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
use App\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Head\Enums\OgType;
use Laravel\Head\Facades\Head;
use Laravel\Head\HeadBuilder;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Telescope is a development tool only. It is a dev dependency and is
        // excluded from package discovery, so it is registered by hand here
        // and never exists in any other environment.
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Scoped, not singleton: Octane and the queue worker must get a fresh
        // Tenant per request or job rather than reusing the previous one's.
        $this->app->scoped(Tenant::class);
    }

    public function boot(): void
    {
        $this->configureDatabase();
        $this->configureTokens();
        $this->configureHead();
        $this->configureInertia();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    protected function configureDatabase(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->guardOwnerConnection();
    }

    protected function guardOwnerConnection(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName === 'owner' && ! $this->app->runningInConsole()) {
                throw new RuntimeException(
                    'The owner database connection may not be used outside a console run. '.
                    'Migrate with `composer migrate` (php artisan migrate --database=owner) or run `php artisan db:grant`.'
                );
            }
        });
    }

    protected function configureTokens(): void
    {
        Sanctum::usePersonalAccessTokenModel(Secret::class);

        Passport::useClientModel(Client::class);
        Passport::useTokenModel(Token::class);
        Passport::useRefreshTokenModel(Refresh::class);
        Passport::useAuthCodeModel(Code::class);
        Passport::useDeviceCodeModel(Device::class);
    }

    protected function configureInertia(): void
    {
        Inertia::disableSsr(fn (Request $request) => ! $request->route()?->getMetadata('ssr', false));
    }

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

    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user) => $user->isPlatform() ? true : null);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->allows($permission));
        }
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower($request->string('email')).'|'.$request->ip()));

        RateLimiter::for('ledger-verification', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
