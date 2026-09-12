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

Route::get('/', HomeController::class)->name('home')
    ->withHead(
        title: 'Scheduling and daily time records',
        description: 'khronoz is scheduling, biometric timelogs and CS Form 48 for Philippine government agencies and private offices, computed under Civil Service Commission rules.',
    )
    ->metadata(['ssr' => true]);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('users', UserController::class)->except(['show']);
    Route::post('users/{user}/invite', [UserInviteController::class, 'store'])->name('users.invite');

    Route::middleware('agency')->group(function () {
        Route::resource('employees', EmployeeController::class);
        Route::post('employees/{employee}/deployments', [EmployeeDeploymentController::class, 'store'])->name('employees.deployments.store');
        Route::patch('employees/{employee}/deployments', [EmployeeDeploymentController::class, 'update'])->name('employees.deployments.update');
        Route::delete('employees/{employee}/deployments/{deployment}', [EmployeeDeploymentController::class, 'destroy'])
            ->scopeBindings()
            ->name('employees.deployments.destroy');
        Route::resource('workgroups', WorkgroupController::class)->except(['show']);
        Route::resource('terminals', TerminalController::class)->except(['show']);
        Route::resource('shifts', ShiftController::class)->except(['show']);
        Route::resource('schedules', ScheduleController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);

        Route::get('rosters', [RosterController::class, 'index'])->name('rosters.index');
        Route::post('rosters', [RosterController::class, 'store'])->name('rosters.store');
        Route::delete('rosters/{roster}', [RosterController::class, 'destroy'])->name('rosters.destroy');

        Route::get('defaults', [DefaultController::class, 'index'])->name('defaults.index');
        Route::post('defaults/copy', [DefaultController::class, 'copy'])->name('defaults.copy');
        Route::post('defaults/refresh', [DefaultController::class, 'refresh'])->name('defaults.refresh');

        Route::resource('holidays', HolidayController::class)->except(['show']);
        Route::resource('suspensions', SuspensionController::class)->except(['show']);
        Route::resource('exemptions', ExemptionController::class)->except(['show']);
        Route::resource('overtimes', OvertimeController::class)->except(['show']);

        Route::get('syncs', [SyncController::class, 'index'])->name('syncs.index');

        Route::get('timelogs', [TimelogController::class, 'index'])->name('timelogs.index');
        Route::patch('timelogs/{timelog}/void', [TimelogController::class, 'void'])->name('timelogs.void');

        Route::get('workdays', [WorkdayController::class, 'index'])->name('workdays.index');
        Route::get('ledgers', [LedgerController::class, 'index'])->name('ledgers.index');
        Route::get('ledgers/{ledger}', [LedgerController::class, 'show'])->name('ledgers.show');
        Route::patch('ledgers/{ledger}/lock', [LedgerController::class, 'lock'])->name('ledgers.lock');
        Route::patch('ledgers/{ledger}/unlock', [LedgerController::class, 'unlock'])->name('ledgers.unlock');

        Route::get('terminals/{terminal}/enrollments', [TerminalEnrollmentController::class, 'index'])
            ->name('terminals.enrollments.index');
        Route::post('terminals/{terminal}/enrollments', [TerminalEnrollmentController::class, 'store'])
            ->name('terminals.enrollments.store');
        Route::patch('terminals/{terminal}/enrollments/{enrollment}', [TerminalEnrollmentController::class, 'update'])
            ->scopeBindings()
            ->name('terminals.enrollments.update');
        Route::post('terminals/{terminal}/syncs', [TerminalSyncController::class, 'store'])
            ->name('terminals.syncs.store');
    });

    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::resource('agencies', AgencyController::class)->except(['show', 'destroy']);
        Route::post('agencies/{agency}/enter', [EnterAgencyController::class, 'store'])->name('agencies.enter');
        Route::delete('enter', [EnterAgencyController::class, 'destroy'])->name('agencies.leave');
    });
});

require __DIR__.'/auth.php';
