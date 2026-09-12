<?php

namespace App\Http\Controllers;

use App\Enums\Period;
use App\Enums\ReportDay;
use App\Enums\Work;
use App\Http\Resources\CadenceResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LedgerResource;
use App\Http\Resources\WorkdayResource;
use App\Models\Cadence;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Workday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        $query = Ledger::query()->select('ledgers.*')
            ->join('employees', 'employees.id', '=', 'ledgers.employee_id')
            ->with([
                'employee' => fn (BelongsTo $employee) => $employee->withTrashed()->with('currentDeployment.workgroup'),
                'renditions' => fn ($renditions) => $renditions->whereNull('superseded_at')->with('document')->latest('revision'),
            ])
            ->where('ledgers.starts', '<=', $month->endOfMonth()->toDateString())
            ->where('ledgers.ends', '>=', $month->toDateString())
            ->orderBy('employees.last_name')->orderBy('employees.first_name')->orderByDesc('ledgers.starts')->orderByDesc('ledgers.revision')->orderBy('ledgers.id');

        foreach (['workdays_count' => 'COUNT(*)', 'worked' => 'SUM(worked)', 'tardy' => 'SUM(tardy)', 'undertime' => 'SUM(undertime)'] as $alias => $aggregate) {
            $query->addSelect([$alias => Workday::query()->selectRaw($aggregate)
                ->whereColumn('workdays.employee_id', 'ledgers.employee_id')
                ->whereColumn('workdays.agency_id', 'ledgers.agency_id')
                ->whereColumn('workdays.date', '>=', 'ledgers.starts')
                ->whereColumn('workdays.date', '<=', 'ledgers.ends')]);
        }
        $ledgers = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('ledgers/index', [
            'ledgers' => LedgerResource::collection($ledgers->getCollection())->resolve(),
            'pagination' => ['from' => $ledgers->firstItem(), 'to' => $ledgers->lastItem(), 'total' => $ledgers->total(), 'previous' => $ledgers->previousPageUrl(), 'next' => $ledgers->nextPageUrl()],
            'filters' => ['month' => $month->format('Y-m')],
            'employees' => EmployeeResource::collection(Employee::withTrashed()->orderBy('last_name')->orderBy('first_name')->get())->resolve(),
            'cadences' => CadenceResource::collection(Cadence::orderBy('retired_at')->orderBy('name')->get())->resolve(),
            'works' => Work::choices(),
            'reportDays' => ReportDay::choices(),
        ]);
    }

    public function show(Request $request, Ledger $ledger): Response
    {
        Gate::authorize('view', $ledger);
        $ledger->load([
            'employee' => fn (BelongsTo $employee) => $employee->withTrashed()->with('currentDeployment.workgroup'),
            'cadence',
            'attestations' => fn ($query) => $query->orderBy('sequence')->orderBy('at'),
            'renditions' => fn ($query) => $query->with('document')->orderByDesc('requested_at'),
        ]);
        $period = Period::tryFrom($request->string('period')->toString()) ?? Period::Full;
        $work = Work::tryFrom($request->string('work')->toString()) ?? $ledger->scope;
        if ($ledger->calculation !== null) {
            $calculation = $ledger->calculation;
            $totals = $calculation['totals'];
            $view = [...$totals, 'workdays' => $calculation['workdays'],
                'night_excess' => $totals['nightExcess'] ?? $totals['night_excess'] ?? 0,
                'tardy_occurrences' => $totals['tardyOccurrences'] ?? $totals['tardy_occurrences'] ?? 0,
                'undertime_occurrences' => $totals['undertimeOccurrences'] ?? $totals['undertime_occurrences'] ?? 0,
            ];
        } else {
            $computed = $ledger->view($period, $work);
            $computed->workdays->load(['punches' => fn ($query) => $query->orderBy('slot')->orderBy('expected_at'), 'exemption']);
            $view = [...get_object_vars($computed), 'workdays' => WorkdayResource::collection($computed->workdays)->resolve(),
                'night_excess' => $computed->nightExcess, 'tardy_occurrences' => $computed->tardyOccurrences, 'undertime_occurrences' => $computed->undertimeOccurrences,
            ];
        }

        return Inertia::render('ledgers/show', [
            'ledger' => LedgerResource::make($ledger)->resolve(),
            'view' => $view, 'periods' => Period::choices(), 'works' => Work::choices(),
            'can' => [
                'lock' => $request->user()->can('lock', $ledger),
                'unlock' => $ledger->locked() && $request->user()->can('unlock', $ledger),
                'attest' => $request->user()->can('attest', $ledger),
                'retry' => $request->user()->can('ledgers.manage'),
            ],
        ]);
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();
        if (preg_match('/^(\\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }
}
