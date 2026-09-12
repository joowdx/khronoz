<?php

namespace App\Tenancy;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Support\Facades\Context;

/**
 * The agency the current request or job works inside. Bound `scoped`, so
 * Octane and the queue worker get a fresh instance per request or job; the id
 * is mirrored into hidden Context so a queued job restores it.
 */
final class Tenant
{
    private ?Agency $agency = null;

    private bool $resolved = false;

    private ?string $platformId = null;

    public function set(Agency $agency): void
    {
        $this->agency = $agency;
        $this->resolved = true;
        Context::addHidden('agency', $agency->getKey());
    }

    public function forget(): void
    {
        $this->agency = null;
        $this->resolved = true;
        Context::forgetHidden('agency');
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function within(?Agency $agency, callable $work): mixed
    {
        $previous = $this->agency();

        $agency === null ? $this->forget() : $this->set($agency);

        try {
            return $work();
        } finally {
            $previous === null ? $this->forget() : $this->set($previous);
        }
    }

    public function agency(): ?Agency
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $id = Context::getHidden('agency');
            $this->agency = $id ? Agency::withoutGlobalScope(NotPlatformScope::class)->find($id) : null;
        }

        return $this->agency;
    }

    public function id(): ?string
    {
        return $this->agency()?->getKey();
    }

    public function check(): bool
    {
        return $this->agency() !== null;
    }

    public function platformId(): string
    {
        return $this->platformId ??= Agency::platform()->getKey();
    }
}
