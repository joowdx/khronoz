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

        // Scoped, not singleton: Octane and the queue worker must get a fresh
        // Tenant per request or job rather than reusing the previous one's.
        $this->app->scoped(Tenant::class);
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
        $this->configureRateLimiting();
    }

    /**
     * Fail loudly in development and refuse destructive commands in production.
     */
    protected function configureDatabase(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->guardOwnerConnection();
    }

    /**
     * `DB_OWNER_*` being unset only documents the privilege boundary; it does
     * not enforce it (config/database.php no longer defaults them, but a
     * shared DB_URL or a misconfigured deploy could still populate them). This
     * guard catches one shape of that misuse: the owner connection — which
     * bypasses every REVOKE the migrations put in place — resolved from a
     * request served through a non-CLI SAPI (php-fpm, apache2handler), the
     * runtime this project's web tier does not use.
     *
     * It does NOT catch Octane's HTTP workers (`php artisan octane:start`) or
     * Horizon's queue workers (`php artisan horizon`): both are long-running
     * CLI processes, exactly like `composer migrate` itself, and neither
     * package flips the flag per request or job. Application::runningInConsole()
     * only checks PHP_SAPI (cli or phpdbg), memoized once for the life of the
     * process, so inside either worker it is permanently true and the
     * exception below can never fire — the guard cannot tell a real migration
     * run apart from a stray owner-connection query made from application
     * code running under Octane or Horizon. `DB_OWNER_*` having no fallback
     * defaults is therefore the real protection in those two runtimes; a
     * signal that can actually distinguish them — an explicit context flag
     * set only by the migrate and grant commands, or gating on the running
     * artisan command's name — is deferred to Milestone 9.
     */
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

    /**
     * Keyed by email plus IP rather than either alone: an IP-only key would
     * let one attacker lock out every account behind a shared network, and
     * an email-only key would let an attacker distributed across IPs still
     * brute-force a single account.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower($request->string('email')).'|'.$request->ip()));
    }
}
