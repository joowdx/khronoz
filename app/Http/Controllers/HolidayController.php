<?php

namespace App\Http\Controllers;

use App\Attendance\Week;
use App\Enums\HolidayType;
use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Jobs\FanOutRecompute;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dates on which no work is expected, or on which work is paid at a
 * premium (05-calendar.md rule 1).
 *
 * This is the one table read under AgencyOrPlatformScope, so every list here
 * mixes the tenant's own holidays with the national ones the platform agency
 * declares. The mix is the feature — an agency should see what it is working
 * against — and the rows it cannot change are refused by HolidayPolicy rather
 * than merely dimmed.
 *
 * **Every read is plural.** A date may carry more than one holiday and both
 * are owed, so nothing here reaches for `->first()` on a date; the year filter
 * and the ordering are by date, and two rows sharing one are two rows.
 */
class HolidayController extends Controller
{
    use TranslatesUniqueCollisions;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Holiday::class);

        $year = $this->year($request->string('year')->trim()->toString());

        $holidays = Holiday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('name')
            ->get();

        return Inertia::render('holidays/index', [
            'holidays' => HolidayResource::collection($holidays)->resolve(),
            'filters' => ['year' => (string) $year],
            // The years this agency actually has holidays in, so the picker
            // offers nothing empty. A closure: it is the filter control's own
            // options and must not be re-queried on every change.
            'years' => fn () => $this->years(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Holiday::class);

        return Inertia::render('holidays/create', [
            'rates' => HolidayType::choices(),
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        $holiday = $this->translatingCollisions(['holidays_agency_id_date_name_unique' => 'name'], fn () => Holiday::create($request->validated()));

        $this->recompute($this->reach($holiday));

        return to_route('holidays.index', ['year' => $holiday->date->year])
            ->with('success', 'Holiday added.');
    }

    public function edit(Holiday $holiday): Response
    {
        Gate::authorize('update', $holiday);

        return Inertia::render('holidays/edit', [
            'holiday' => HolidayResource::make($holiday)->resolve(),
            'rates' => HolidayType::choices(),
        ]);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        // Both reaches: a proclamation corrected to another date gives back
        // the day it was wrongly taken from.
        $before = $this->reach($holiday);

        $this->translatingCollisions(['holidays_agency_id_date_name_unique' => 'name'], fn () => $holiday->update($request->validated()));

        $this->recompute($before);
        $this->recompute($this->reach($holiday->refresh()), $before);

        return to_route('holidays.index', ['year' => $holiday->date->year])
            ->with('success', 'Holiday updated.');
    }

    /**
     * Nothing references a holiday, so this is a plain delete — no RESTRICT to
     * translate. The deriver reads the table by date at compute time rather
     * than holding a foreign key to it (decision 30's shape), which is what
     * makes removing a wrongly entered proclamation safe.
     */
    public function destroy(Holiday $holiday): RedirectResponse
    {
        Gate::authorize('delete', $holiday);

        $year = $holiday->date->year;
        $reach = $this->reach($holiday);
        $holiday->delete();

        $this->recompute($reach);

        return to_route('holidays.index', ['year' => $year])->with('success', 'Holiday removed.');
    }

    /**
     * Who a proclamation reaches, and over which days (Workday rule 3,
     * decision 86).
     *
     * **The whole ISO week, not the date.** Rule 3 asks for the week only
     * for a compressed roster whose Off turn the holiday lands on — a CWW
     * week redistributes its hours, so the four days that are worked change
     * when the fifth is declared — and nothing at the point of declaring it
     * knows whose roster is compressed. The week is seven recomputes of a
     * clamped span instead of one, and it is the difference between the rule
     * holding and a controller guessing at rosters it has not loaded.
     *
     * A national holiday belongs to the platform agency and reaches every
     * tenant, which is `AgencyOrPlatformScope`'s rule read from the writing
     * side. Its id list is the one this job will not carry, so it travels as
     * no scope at all. **No screen declares one yet** — these routes are in
     * the agency group and a platform user who has entered no agency gets a
     * 404 — so that limb is written for the platform calendar screen rather
     * than reached today; `Holiday::national()` is where it is tested.
     *
     * @return array{platform: bool, agency: string, from: string, to: string}
     */
    private function reach(Holiday $holiday): array
    {
        [$from, $to] = Week::bounds($holiday->date->toImmutable());

        return [
            'platform' => $holiday->national(),
            'agency' => $holiday->agency_id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @param  array{platform: bool, agency: string, from: string, to: string}  $reach
     * @param  ?array{platform: bool, agency: string, from: string, to: string}  $unless  Already queued.
     */
    private function recompute(array $reach, ?array $unless = null): void
    {
        if ($reach === $unless) {
            return;
        }

        $reach['platform']
            ? FanOutRecompute::forEveryAgency($reach['from'], $reach['to'])
            : FanOutRecompute::forAgency($reach['agency'], $reach['from'], $reach['to']);
    }

    /** A four-digit year, defaulting to the current one. */
    private function year(string $value): int
    {
        return preg_match('/^\d{4}$/', $value) === 1 ? (int) $value : (int) today()->year;
    }

    /** @return array<int, string> */
    private function years(): array
    {
        return Holiday::query()
            ->selectRaw('distinct extract(year from date)::int as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn (int $year) => (string) $year)
            ->all();
    }
}
