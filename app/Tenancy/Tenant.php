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
     * Run `$work` with `$agency` set — or with none — and put back whatever
     * was set before (decision 86, superseding decision 84's mechanism).
     *
     * Decision 84's rule was that a job must leave no tenant behind, and its
     * `finally { forget() }` was right for the only caller it had: a queue
     * worker, whose container is discarded and whose next job would otherwise
     * inherit this one's agency. It is wrong for `QUEUE_CONNECTION=sync`,
     * where the "worker" is the request that dispatched the job and the
     * container is the request's own. A controller that recorded a holiday
     * and then queued a recompute came back to a cleared tenant and every
     * later query in that request answered "SetTenant did not run" — the
     * job stole the tenant out from under its own caller.
     *
     * Restoring is the same rule stated exactly: leave behind *what you
     * found*. In a worker that is nothing, so decision 84's behaviour is
     * unchanged; under sync it is the caller's agency.
     *
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
