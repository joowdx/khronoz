<?php

namespace App\Listeners;

use SocialiteProviders\Apple\Provider as Apple;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Socialite ships Google in core; Apple arrives through SocialiteProviders
 * and has to be registered on the manager before it can be resolved.
 */
class ExtendSocialite
{
    public function handle(SocialiteWasCalled $event): void
    {
        $event->extendSocialite('apple', Apple::class);
    }
}
