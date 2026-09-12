<?php

namespace App\Http\Controllers;

use App\Actions\LockLedger;
use App\Actions\UnlockLedger;
use App\Attendance\LedgerView;
use App\Enums\Period;
use App\Enums\Work;
use App\Http\Resources\LedgerResource;
use App\Http\Resources\WorkdayResource;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Ledger::class);

        $month = $this->month($request);

        $ledgers = Ledger::query()
            ->select('ledgers.*')
            ->join('employees', 'employees.id', '=', 'ledgers.employee_id')
            ->with([
                'employee' => fn (BelongsTo $employee) => $employee
                    ->withTrashed()
                    ->with('currentDeployment.workgroup'),
            ])
            ->withCount('workdays')
            ->withSum('workdays as worked', 'worked')
            ->withSum('workdays as tardy', 'tardy')
            ->withSum('workdays as undertime', 'undertime')
            ->where('ledgers.month', $month->toDateString())
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('ledgers.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('ledgers/index', [
            'ledgers' => LedgerResource::collection($ledgers->getCollection())->resolve(),
            'pagination' => [
                'from' => $ledgers->firstItem(),
                'to' => $ledgers->lastItem(),
                'total' => $ledgers->total(),
                'previous' => $ledgers->previousPageUrl(),
                'next' => $ledgers->nextPageUrl(),
            ],
            'filters' => [
                'month' => $month->format('Y-m'),
            ],
        ]);
    }

    public function show(Request $request, Ledger $ledger): Response
    {
        Gate::authorize('view', $ledger);

        $period = Period::tryFrom($request->string('period')->trim()->toString()) ?? Period::Full;
        $work = Work::tryFrom($request->string('work')->trim()->toString());

        $ledger->load([
            'employee' => fn (BelongsTo $employee) => $employee
                ->withTrashed()
                ->with('currentDeployment.workgroup'),
        ]);

        $view = $ledger->view($period, $work);
        $view->workdays->load([
            'punches' => fn (HasMany $punches) => $punches->orderBy('slot')->orderBy('expected_at'),
            'exemption',
        ]);

        return Inertia::render('ledgers/show', [
            'ledger' => LedgerResource::make($ledger)->resolve(),
            'view' => $this->viewPayload($view),
            'periods' => Period::choices(),
            'works' => Work::choices(),
            'can' => [
                'lock' => $request->user()->can('lock', $ledger),
                'unlock' => $request->user()->can('unlock', $ledger),
            ],
        ]);
    }

    public function lock(Ledger $ledger, LockLedger $lock): RedirectResponse
    {
        Gate::authorize('lock', $ledger);

        try {
            $lock->handle($ledger);
        } catch (QueryException $e) {
            if ($e->getCode() !== 'P0001') {
                throw $e;
            }

            return back()->with('error', 'This month still has a punch due. It can be locked once the last shift has ended.');
        }

        return back()->with('success', 'Ledger locked.');
    }

    public function unlock(Ledger $ledger, UnlockLedger $unlock): RedirectResponse
    {
        Gate::authorize('unlock', $ledger);

        try {
            $unlock->handle($ledger);
        } catch (QueryException $e) {
            if ($e->getCode() !== 'P0001') {
                throw $e;
            }

            return back()->with('error', 'Remove the signatures before unlocking. You certify frozen numbers, never moving ones.');
        }

        return back()->with('success', 'Ledger unlocked.');
    }

    /**
     * `YYYY-MM`; defaults to the current month. A malformed value falls back
     * to the default rather than filtering by garbage.
     */
    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }

    /**
     * @return array<string, mixed>
     */
    private function viewPayload(LedgerView $view): array
    {
        return [
            'workdays' => WorkdayResource::collection($view->workdays)->resolve(),
            'worked' => $view->worked,
            'credited' => $view->credited,
            'tardy' => $view->tardy,
            'undertime' => $view->undertime,
            'excess' => $view->excess,
            'night' => $view->night,
            'night_excess' => $view->nightExcess,
            'overtime' => $view->overtime,
            'tardy_occurrences' => $view->tardyOccurrences,
            'undertime_occurrences' => $view->undertimeOccurrences,
            'absences' => $view->absences,
        ];
    }
}
