<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\TeamResource;
use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A named `(schedule, anchor)` cohort (04-scheduling.md).
 *
 * **A team has no membership of its own.** There is no pivot to write and none
 * to read: the rosters carrying a team's `team_id` *are* its members, which is
 * why the headcount here is `withCount('rosters')` and why nothing on these
 * screens adds or removes a person. Rostering is the roster grid's act.
 *
 * And no date range: a team is a standing definition, not an arrangement with
 * a start and an end. What the anchor buys is the whole reason the model
 * exists — three teams on one 21-day rotation, anchored seven days apart, sit
 * seven positions apart on every date, so a hospital covers morning, afternoon
 * and night with one schedule and three rows.
 *
 * Re-anchoring changes the definition only; the rosters already issued keep
 * their own anchor and may diverge from it (07-constraints.md deliberately has
 * no trigger holding them equal), so a past rotation stays answerable.
 */
class TeamController extends Controller
{
    use TranslatesUniqueCollisions;

    /**
     * Every team with its schedule and its headcount.
     *
     * `schedule.turns.shift` is eager-loaded because `ScheduleResource`
     * resolves its turns whenever they are there, and the row shows the cycle
     * it belongs to. No pagination: an agency has teams in single figures.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Team::class);

        $teams = Team::query()
            ->with(['schedule.turns.shift', 'schedule.fallbackShift'])
            // The membership itself. Every roster ever issued from this team,
            // closed ones included — it is what stands in the way of removing
            // it, so the number beside the action has to count the same rows
            // the RESTRICT does.
            ->withCount('rosters')
            ->orderBy('name')
            ->get();

        return Inertia::render('teams/index', [
            'teams' => TeamResource::collection($teams)->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Team::class);

        return Inertia::render('teams/create', [
            'schedules' => fn () => $this->schedules(),
        ]);
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $this->translatingCollisions(
            ['teams_agency_id_name_unique' => 'name'],
            fn () => Team::create($request->validated()),
        );

        return to_route('teams.index')->with('success', 'Team added.');
    }

    public function edit(Team $team): Response
    {
        Gate::authorize('update', $team);

        return Inertia::render('teams/edit', [
            'team' => TeamResource::make($team)->resolve(),
            'schedules' => fn () => $this->schedules(),
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $this->translatingCollisions(
            ['teams_agency_id_name_unique' => 'name'],
            fn () => $team->update($request->validated()),
        );

        return to_route('teams.index')->with('success', 'Team updated.');
    }

    /**
     * A team whose rosters still name it is refused by `(team_id, agency_id)`
     * (23001), and the message says so rather than letting a 500 out. The list
     * can be stale and the route is reachable by URL, so hiding the action is
     * not translating the refusal — both are needed.
     */
    public function destroy(Team $team): RedirectResponse
    {
        Gate::authorize('delete', $team);

        try {
            // Its own transaction, so the refusal is recoverable rather than
            // merely caught: a failed statement leaves the surrounding
            // transaction aborted and the redirect's own queries answer 25P02
            // (.ai/rules/controllers.md).
            DB::transaction(fn () => $team->delete());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23001') {
                throw $e;
            }

            return to_route('teams.index')
                ->with('error', "{$team->name} cannot be removed while anyone's roster still names it.");
        }

        return to_route('teams.index')->with('success', "{$team->name} removed.");
    }

    /**
     * The schedules a team may follow: this agency's own, with their cycles
     * drawn, so the picker shows what the team will actually work.
     *
     * @return array<int, array<string, mixed>>
     */
    private function schedules(): array
    {
        return ScheduleResource::collection(
            Schedule::query()->with(['turns.shift', 'fallbackShift'])->orderBy('name')->get()
        )->resolve();
    }
}
