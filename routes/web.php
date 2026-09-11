<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DefaultController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDeploymentController;
use App\Http\Controllers\ExemptionController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\Platform\AgencyController;
use App\Http\Controllers\Platform\EnterAgencyController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SuspensionController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TerminalEnrollmentController;
use App\Http\Controllers\TerminalSyncController;
use App\Http\Controllers\TimelogController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInviteController;
use App\Http\Controllers\WorkdayController;
use App\Http\Controllers\WorkgroupController;
use Illuminate\Support\Facades\Route;

// The product's public face: server-rendered so crawlers and link previews
// get the real document, and the only route in this file open to guests.
Route::get('/', HomeController::class)->name('home')
    ->withHead(
        title: 'Scheduling and daily time records',
        description: 'khronoz is scheduling, biometric timelogs and CS Form 48 for Philippine government agencies and private offices, computed under Civil Service Commission rules.',
    )
    ->metadata(['ssr' => true]);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Any authenticated user holding users.manage may invite and manage the
    // colleagues of their own tenant; UserPolicy enforces that permission per
    // action, and UserController lists through the tenant's agency relation,
    // never a bare User::query() — see app/Models/Concerns/BelongsToAgency.php.
    Route::resource('users', UserController::class)->except(['show']);
    Route::post('users/{user}/invite', [UserInviteController::class, 'store'])->name('users.invite');

    // The org tree and its people (docs/design/01-organization.md). WorkgroupPolicy
    // and EmployeePolicy gate on organization.view/organization.manage.
    // Deployment moves and endings share the nested employee boundary;
    // removal is a distinct transaction owned by RemoveEmployee.
    //
    // `agency` (EnsureAgency) is the boundary, not a courtesy: both tables
    // carry an agency_not_platform trigger, so a platform user sitting on the
    // platform tenant could reach /employees/create, fill it in and submit it
    // into an uncaught P0001. The sidebar hides the group for them; this is
    // what makes the routes themselves absent.
    Route::middleware('agency')->group(function () {
        Route::resource('employees', EmployeeController::class);
        Route::post('employees/{employee}/deployments', [EmployeeDeploymentController::class, 'store'])->name('employees.deployments.store');
        Route::patch('employees/{employee}/deployments', [EmployeeDeploymentController::class, 'update'])->name('employees.deployments.update');
        // Correction, not amendment: a wrongly recorded deployment is deleted
        // and the right one created afresh (decision 35). Collection-level
        // PATCH above ends the open placement — a different operation on a
        // row the caller does not name — so this one is member-level and
        // ->scoped() ties {deployment} to {employee}, making another
        // employee's row a 404 rather than something this route can delete.
        Route::delete('employees/{employee}/deployments/{deployment}', [EmployeeDeploymentController::class, 'destroy'])
            ->scopeBindings()
            ->name('employees.deployments.destroy');
        Route::resource('workgroups', WorkgroupController::class)->except(['show']);
        Route::resource('terminals', TerminalController::class)->except(['show']);
        // Scheduling (04-scheduling.md). A shift is a day template, a schedule
        // a cycle of shifts, a team a named (schedule, anchor) cohort, and a
        // roster the only assignment there is.
        //
        // Inside `agency` like the calendar, and for the same reason: `teams`
        // carries agency_not_platform, and a roster needs an employee, which
        // the platform tenant may not hold. Platform-owned shifts and schedules
        // are product data seeded by DefaultsSeeder and reached only through
        // the defaults screen below — an agency copies or refreshes them, and
        // nobody edits them through a request.
        Route::resource('shifts', ShiftController::class)->except(['show']);
        Route::resource('schedules', ScheduleController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);

        // The roster grid, and the two writes it offers. Assignment is a POST
        // to the collection because AssignSchedule *issues* a roster — it
        // closes the standing one at `starts - 1` in the same transaction,
        // which is one act, not an edit of the row it supersedes. There is no
        // update: a wrongly dated roster is deleted and reissued (decision 35).
        Route::get('rosters', [RosterController::class, 'index'])->name('rosters.index');
        Route::post('rosters', [RosterController::class, 'store'])->name('rosters.store');
        Route::delete('rosters/{roster}', [RosterController::class, 'destroy'])->name('rosters.destroy');

        // Platform defaults an agency may copy or refresh from (rule 7). Its
        // own controller rather than a method on ShiftController: it lists
        // shifts *and* schedules and belongs to neither.
        Route::get('defaults', [DefaultController::class, 'index'])->name('defaults.index');
        Route::post('defaults/copy', [DefaultController::class, 'copy'])->name('defaults.copy');
        Route::post('defaults/refresh', [DefaultController::class, 'refresh'])->name('defaults.refresh');

        // The calendar: what changes what was expected of a day
        // (05-calendar.md). `holidays` is the one table read under
        // AgencyOrPlatformScope, so its list mixes this agency's rows with the
        // national ones — HolidayPolicy refuses editing the latter.
        Route::resource('holidays', HolidayController::class)->except(['show']);
        Route::resource('suspensions', SuspensionController::class)->except(['show']);
        Route::resource('exemptions', ExemptionController::class)->except(['show']);
        Route::resource('overtimes', OvertimeController::class)->except(['show']);

        // Every ingestion run, successful or refused. Read-only entirely:
        // the app role has no DELETE here, because a run record that can be
        // deleted is a run that can be denied.
        Route::get('syncs', [SyncController::class, 'index'])->name('syncs.index');

        // What the devices recorded. Read-only apart from voiding, which is
        // the schema's doing rather than a choice: the app role has no DELETE
        // here and its UPDATE is revoked to (voided_at, reason) — decision 41.
        Route::get('timelogs', [TimelogController::class, 'index'])->name('timelogs.index');
        Route::patch('timelogs/{timelog}/void', [TimelogController::class, 'void'])->name('timelogs.void');

        // What the engine derived, and whose month is done. Read-only apart
        // from lock/unlock; the database's triggers are the rule (decision 70).
        Route::get('workdays', [WorkdayController::class, 'index'])->name('workdays.index');
        Route::get('ledgers', [LedgerController::class, 'index'])->name('ledgers.index');
        Route::get('ledgers/{ledger}', [LedgerController::class, 'show'])->name('ledgers.show');
        Route::patch('ledgers/{ledger}/lock', [LedgerController::class, 'lock'])->name('ledgers.lock');
        Route::patch('ledgers/{ledger}/unlock', [LedgerController::class, 'unlock'])->name('ledgers.unlock');

        // Who a terminal can identify, over time — and in effect the
        // terminal's own page: TerminalController has no `show` because a
        // terminal's facts fit in the list, but its people do not. Scoped
        // bindings so an enrollment of another terminal 404s.
        Route::get('terminals/{terminal}/enrollments', [TerminalEnrollmentController::class, 'index'])
            ->name('terminals.enrollments.index');
        Route::post('terminals/{terminal}/enrollments', [TerminalEnrollmentController::class, 'store'])
            ->name('terminals.enrollments.store');
        Route::patch('terminals/{terminal}/enrollments/{enrollment}', [TerminalEnrollmentController::class, 'update'])
            ->scopeBindings()
            ->name('terminals.enrollments.update');
        // Ingestion. A sync is a *record of a run*, so creating one is the act
        // of importing — hence POST to the collection rather than a verb URL.
        // Milestone 5 ships file import only (decision 40); push and pull will
        // add their own entry points against the same action.
        Route::post('terminals/{terminal}/syncs', [TerminalSyncController::class, 'store'])
            ->name('terminals.syncs.store');
    });

    // Platform users only (superusers of the one platform = true agency):
    // list, create and edit agencies, and "enter" one to adopt it as the
    // tenant for the rest of the session — see SetTenant.
    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::resource('agencies', AgencyController::class)->except(['show', 'destroy']);
        Route::post('agencies/{agency}/enter', [EnterAgencyController::class, 'store'])->name('agencies.enter');
        Route::delete('enter', [EnterAgencyController::class, 'destroy'])->name('agencies.leave');
    });
});

require __DIR__.'/auth.php';
