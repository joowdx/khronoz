<?php

namespace App\Actions;

use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Put an agency's copy back to the platform default it came from
 * (04-scheduling.md rule 7).
 *
 * **It restores exactly what the defaults screen calls "differs", and nothing
 * else.** For a shift that is `slots`, `required`, `flex`, `remote` and
 * `trust`; for a schedule it is `length` and the ordered turns. `name` and
 * `color` are deliberately left alone — neither is compared, so refreshing
 * them would change something the screen never offered to change, and a
 * timekeeper who renamed their copy or moved it to a free ramp slot would
 * find both undone by a button that said it was only catching up with the
 * default. `fallback_shift_id` is left alone for the same reason.
 *
 * **The remap is the same problem `CopyDefaults` has.** The origin's turns
 * name *platform* shifts, and the paired FK `(shift_id, agency_id)` forbids an
 * agency's turn pointing at one, so each is translated to this agency's own
 * shift — by `origin_id` first, then by name, mirroring `CopyDefaults`' rule
 * that an agency's same-named row answers for a default it could not copy.
 *
 * **A shift it cannot translate is a refusal, not an invention.** When the
 * platform has added a turn using a shift this agency has never copied, the
 * whole refresh answers false and writes nothing: the same screen lists that
 * shift as uncopied with Copy beside it, so the fix is one click away and is
 * the agency's to make. Creating the shift here instead would mean a button
 * labelled "Refresh" quietly adding rows to another table.
 *
 * **One transaction, because `turns_complete` is deferred.** It checks at
 * COMMIT that a schedule has exactly `length` turns at 0..length-1, and a
 * refresh is a delete of the whole cycle followed by an insert of the whole
 * cycle — momentarily empty, and legal only because nothing commits in
 * between.
 */
final class RefreshFromOrigin
{
    /**
     * Refresh $copy from its origin.
     *
     * Answers false — having written nothing — when there is nothing to
     * refresh from (`origin_id` null, or the origin deleted, which the FK's
     * ON DELETE SET NULL turns into the same thing), or when the origin's
     * cycle names a shift this agency does not have. Both are states the
     * screen does not offer a Refresh button for, so a false here means a
     * hand-made request or a list that went stale, and the controller says so
     * rather than raising.
     */
    public function handle(Shift|Schedule $copy): bool
    {
        return DB::transaction(function () use ($copy): bool {
            // The origin is a platform row, so the relation's own AgencyScope
            // would answer null for it under this agency's tenant — the whole
            // trap this screen exists inside, and the one line where forgetting
            // it looks like "the copy has no origin" rather than like an error.
            $origin = $copy->origin()->withoutGlobalScopes([AgencyScope::class])->first();

            if ($origin === null) {
                return false;
            }

            return $copy instanceof Shift
                ? $this->refreshShift($copy, $origin)
                : $this->refreshSchedule($copy, $origin);
        });
    }

    /**
     * The five columns the screen compares, taken together.
     *
     * Together and not one by one: `shifts_remote_has_no_slots`,
     * `shifts_off_credits_nothing` and `shifts_off_has_no_flex` relate `slots`
     * to `remote`, `required` and `flex`, so any partial write is a CHECK
     * violation waiting to happen. The origin satisfies all three, so a copy
     * of all five does too.
     */
    private function refreshShift(Shift $copy, Shift $origin): bool
    {
        $copy->update([
            'slots' => $origin->slots,
            'required' => $origin->required,
            'flex' => $origin->flex,
            'remote' => $origin->remote,
            'trust' => $origin->trust,
        ]);

        return true;
    }

    /**
     * The length and the whole cycle, rewritten rather than reconciled.
     *
     * Diffing turn by turn would save some churn on rows nothing references —
     * no foreign key points at `turns` — and would cost a second way for the
     * cycle to end up neither the old one nor the new one. The delete and the
     * insert share this action's transaction, so the deferred
     * `turns_complete` sees only the finished set.
     */
    private function refreshSchedule(Schedule $copy, Schedule $origin): bool
    {
        $cycle = Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('schedule_id', $origin->id)
            ->orderBy('position')
            ->get();

        // Defensive: the origin is a platform row `turns_complete` already
        // holds on, so a short cycle cannot happen — and writing one would
        // raise P0001 at COMMIT, long after this returned true.
        if ($cycle->count() !== $origin->length) {
            return false;
        }

        $map = $this->shiftsFor($copy, $cycle);

        if ($map === null) {
            return false;
        }

        $copy->update(['length' => $origin->length]);

        Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $copy->agency_id)
            ->where('schedule_id', $copy->id)
            ->delete();

        foreach ($cycle as $turn) {
            Turn::create([
                'agency_id' => $copy->agency_id,
                'schedule_id' => $copy->id,
                'shift_id' => $map[$turn->shift_id],
                'position' => $turn->position,
            ]);
        }

        return true;
    }

    /**
     * `platform shift id => this agency's shift id` for every shift $cycle
     * uses, or null when one of them has no counterpart here.
     *
     * By `origin_id` first and by name second, which is `CopyDefaults`'
     * collision rule read from the other end: a default whose name was
     * already taken was never copied, and the agency's own row of that name
     * is what a turn naming that default should point at.
     *
     * @param  EloquentCollection<int, Turn>  $cycle
     * @return array<string, string>|null
     */
    private function shiftsFor(Schedule $copy, EloquentCollection $cycle): ?array
    {
        $wanted = Shift::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->whereIn('id', $cycle->pluck('shift_id')->unique()->all())
            ->get();

        $own = Shift::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $copy->agency_id)
            ->get();

        $byOrigin = $own->whereNotNull('origin_id')->keyBy('origin_id');
        $byName = $own->keyBy('name');

        $map = [];

        foreach ($wanted as $shift) {
            $match = $byOrigin->get($shift->id) ?? $byName->get($shift->name);

            if ($match === null) {
                return null;
            }

            $map[$shift->id] = $match->id;
        }

        return $map;
    }
}
