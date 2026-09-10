<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDeploymentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Platform\AgencyController;
use App\Http\Controllers\Platform\EnterAgencyController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInviteController;
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

    // The org tree and its people (docs/design/01-organization.md). UnitPolicy
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
        Route::resource('units', UnitController::class)->except(['show']);
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
