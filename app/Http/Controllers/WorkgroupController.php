<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreWorkgroupRequest;
use App\Http\Requests\UpdateWorkgroupRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\WorkgroupResource;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkgroupController extends Controller
{
    use TranslatesUniqueCollisions;

    public function index(): Response
    {
        Gate::authorize('viewAny', Workgroup::class);

        $workgroups = Workgroup::query()
            ->with('head')

            ->withCount([
                'deployments as people_count' => fn (Builder $query) => $query->whereNull('ends'),

                'deployments',
            ])
            ->orderBy('name')
            ->get();

        $this->rollUpPeople($workgroups);

        return Inertia::render('workgroups/index', [
            'workgroups' => WorkgroupResource::collection($workgroups)->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    /**
     * @param  Collection<int, Workgroup>  $workgroups
     */
    private function rollUpPeople(Collection $workgroups): void
    {
        $byId = $workgroups->keyBy('id');

        /**
         * @var array<string, array<int, string>> $children
         */
        $children = [];

        foreach ($workgroups as $workgroup) {
            $parent = $workgroup->parent_id !== null && $byId->has($workgroup->parent_id) ? $workgroup->parent_id : '';
            $children[$parent][] = $workgroup->id;
        }

        /**
         * @var array<int, array{0: string, 1: bool}> $stack
         */
        $stack = array_map(fn (string $id): array => [$id, false], $children[''] ?? []);

        while ($stack !== []) {
            [$id, $rolled] = array_pop($stack);

            if ($rolled) {
                foreach ($children[$id] ?? [] as $child) {
                    $byId[$id]->people_count += $byId[$child]->people_count;
                }

                continue;
            }

            $stack[] = [$id, true];

            foreach ($children[$id] ?? [] as $child) {
                $stack[] = [$child, false];
            }
        }
    }

    public function create(): Response
    {
        Gate::authorize('create', Workgroup::class);

        return Inertia::render('workgroups/create', [
            'workgroups' => WorkgroupResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    public function store(StoreWorkgroupRequest $request): RedirectResponse
    {
        $workgroup = $this->translatingCollisions(['workgroups_agency_id_code_unique' => 'code'], fn () => Workgroup::create($request->validated()));

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} added.");
    }

    public function edit(Workgroup $workgroup): Response
    {
        Gate::authorize('update', $workgroup);

        return Inertia::render('workgroups/edit', [
            'workgroup' => WorkgroupResource::make($workgroup->load('head'))->resolve(),
            'workgroups' => WorkgroupResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    public function update(UpdateWorkgroupRequest $request, Workgroup $workgroup): RedirectResponse
    {
        try {
            $this->translatingCollisions(['workgroups_agency_id_code_unique' => 'code'], fn () => $workgroup->update($request->validated()));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                'P0001' => ValidationException::withMessages(['parent_id' => ['Under one of its own workgroups.']]),
                '23514' => ValidationException::withMessages(['parent_id' => ['Cannot be its own parent.']]),
                default => $e,
            };
        }

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} updated.");
    }

    /**
     * @return Collection<int, Workgroup>
     */
    private function tree(): Collection
    {
        return Workgroup::query()->orderBy('name')->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function heads(): array
    {
        return EmployeeResource::collection(
            Employee::query()->whereHas('currentDeployment')->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }

    public function destroy(Workgroup $workgroup): RedirectResponse
    {
        Gate::authorize('delete', $workgroup);

        try {

            DB::transaction(fn () => $workgroup->delete());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23001') {
                throw $e;
            }

            return redirect()->route('workgroups.index')
                ->with('error', "{$workgroup->name} cannot be removed while a workgroup sits under it or anyone has ever been deployed to it.");
        }

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} removed.");
    }
}
