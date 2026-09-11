---
paths:
  - 'app/Jobs/**'
---

# Jobs

## WithoutOverlapping, not ShouldBeUnique — and a queued job must set its own tenant
Two traps, both silent.

`ShouldBeUnique` and `WithoutOverlapping` sound alike and do opposite things. `ShouldBeUnique` **deduplicates** — while a job for that key is queued, a second one is dropped. For recompute that means two imports covering different weeks lose the second one, and the DTR is wrong with nothing in the log. What is wanted is that two jobs for one employee never run concurrently, because 06-attendance.md's "Across midnight" rule 1 makes the earlier workday claim a shared timelog first. That is `WithoutOverlapping` with a `releaseAfter`, so a blocked job is re-queued rather than discarded.

A queued job must call `Tenant::set()` as its first act. `AgencyScope` fails closed for HTTP and for tests but returns **without applying any filter** in a console process, and a queue worker is one — so a job that forgets it reads every agency's rows, and `Holiday::covering()` is the one that bites because holidays deliberately widen their scope to the platform agency. The trap on top: under `runningUnitTests()` the scope *does* apply, so a test that sets the tenant itself passes whether or not the job would. The test must `forget()` the tenant first. See decision 61.

## Never name a job method queue(), and restore the tenant rather than clearing it
`Illuminate\Bus\Dispatcher::dispatchToQueue()` does `method_exists($job, 'queue')` and calls it as the custom-queue hook — even a **private** `queue()`, which surfaces as "Call to private method … from scope Illuminate\Bus\Dispatcher" at dispatch. Name the method anything else.

A job that sets a tenant must put back what it found, not `forget()` it (decision 86, superseding decision 84's mechanism). `finally { forget() }` is right only for a worker; under `QUEUE_CONNECTION=sync` the "worker" is the request that dispatched the job, so the controller comes back to a cleared tenant and every later query answers "SetTenant did not run". Use `Tenant::within($agency, $work)`, which leaves behind what it found — nothing in a worker, the caller's agency under sync.
