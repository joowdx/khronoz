<?php

namespace App\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\Sex;
use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\WorkgroupResource;
use App\Jobs\FanOutRecompute;
use App\Models\Employee;
use App\Models\Workgroup;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    use TranslatesUniqueCollisions;

    private const PER_PAGE = 25;

    /**
     * @var array<int, string>|null Memoized: the tag vocabulary is asked for twice on a filtered request.
     */
    private ?array $tags = null;

    public function __construct(private Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $search = $request->string('search')->trim()->toString();
        $exempt = $request->boolean('exempt');

        $tag = $request->string('tag')->trim()->toString();
        $tag = $tag !== '' && in_array($tag, $this->tags(), true) ? $tag : '';

        $workgroup = ($id = $request->string('workgroup')->trim()->toString()) === '' ? null : Workgroup::find($id);
        $workgroupIds = $workgroup === null ? null : [$workgroup->id, ...$workgroup->descendants()->pluck('id')->all()];

        $employees = Employee::search($search)
            ->where('agency_id', $this->tenant->id())
            ->query(fn (Builder $query) => $query
                ->with('currentDeployment.workgroup')
                ->when($workgroupIds !== null, fn (Builder $query) => $query->whereHas(
                    'currentDeployment',
                    fn (Builder $deployment) => $deployment->whereIn('workgroup_id', $workgroupIds),
                ))
                ->when($tag !== '', fn (Builder $query) => $query->whereJsonContains('tags', $tag))
                ->when($exempt, fn (Builder $query) => $query->where('exempt', true))
                ->orderBy('last_name')->orderBy('first_name'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('employees/index', [
            'employees' => EmployeeResource::collection($employees->getCollection())->resolve(),
            'pagination' => [
                'from' => $employees->firstItem(),
                'to' => $employees->lastItem(),
                'total' => $employees->total(),
                'previous' => $employees->previousPageUrl(),
                'next' => $employees->nextPageUrl(),
            ],
            'filters' => [
                'search' => $search,
                'workgroup' => $workgroup?->id ?? '',
                'tag' => $tag,
                'exempt' => $exempt,
            ],

            'workgroups' => fn () => WorkgroupResource::collection($this->workgroups())->resolve(),
            'tags' => fn () => $this->tags(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function tags(): array
    {
        return $this->tags ??= Employee::query()
            ->where('agency_id', $this->tenant->id())
            ->select(DB::raw('DISTINCT jsonb_array_elements_text(tags) AS tag'))
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }

    /**
     * @return Collection<int, Workgroup>
     */
    private function workgroups(): Collection
    {
        return Workgroup::query()->orderBy('name')->get();
    }

    public function create(): Response
    {
        Gate::authorize('create', Employee::class);

        return Inertia::render('employees/create', [
            'sexes' => Sex::choices(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = $this->translatingCollisions(['employees_agency_id_number_unique' => 'number'], fn () => Employee::create($request->validated()));

        return redirect()->route('employees.index')->with('success', "{$employee->name} added.");
    }

    /**
     * Deployment history as a table, not a decorative timeline.
     */
    public function show(Employee $employee): Response
    {
        Gate::authorize('view', $employee);

        $employee->load([
            'currentDeployment.workgroup',
            'deployments' => fn ($query) => $query->with('workgroup')->orderByDesc('starts'),
        ]);

        return Inertia::render('employees/show', [
            'employee' => EmployeeResource::make($employee)->resolve(),

            'workgroups' => WorkgroupResource::collection($this->workgroups())->resolve(),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        Gate::authorize('update', $employee);

        return Inertia::render('employees/edit', [
            'employee' => EmployeeResource::make($employee)->resolve(),
            'sexes' => Sex::choices(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->translatingCollisions(['employees_agency_id_number_unique' => 'number'], fn () => $employee->update($request->validated()));

        return redirect()->route('employees.index')->with('success', "{$employee->name} updated.");
    }

    /**
     * $employee->delete() is a soft delete (SoftDeletes): an UPDATE, not a DELETE, so it never trips workgroups.head_id / deployments.employee_id's ON DELETE RESTRICT.
     */
    public function destroy(Employee $employee, RemoveEmployee $remove): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $remove->handle($employee);

        FanOutRecompute::forEmployees([$employee->id], today()->toDateString());

        return redirect()->route('employees.index')->with('success', "{$employee->name} removed.");
    }
}
