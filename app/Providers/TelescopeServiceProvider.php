<?php

namespace App\Providers;

use App\Support\Dashboard;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if (request()->is('login', 'settings/*', 'confirm-password', '*password*', 'auth/*', 'two-factor-challenge', 'passkeys/*')) {
                return false;
            }

            $content = json_encode($entry->content, JSON_UNESCAPED_SLASHES);
            if (str_contains($content, '/settings/email/verify/') || str_contains($content, 'EmailChangeNotification') || str_contains($content, 'EmailChangedNotification')
                || ($entry->isQuery() && preg_match('/\b(sessions|passkeys|identities)\b/i', $entry->content['sql'] ?? ''))) {
                return false;
            }

            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'current_password', 'token', 'code', 'recovery_code', 'credential', 'oauth', 'passkey', 'login', 'auth', 'password_hash_web', 'id_token', 'access_token', 'refresh_token', 'state']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn ($user = null) => Dashboard::allows($user));
    }
}
