<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'tenant'])->prefix('settings')->group(function () {
    Route::get('/organization', [OrganizationController::class, 'index'])->name('settings.organization');
    Route::patch('/organization', [OrganizationController::class, 'update']);
    Route::post('/showrooms', [OrganizationController::class, 'storeShowroom']);
    Route::patch('/showrooms/{showroom}', [OrganizationController::class, 'updateShowroom']);
    Route::post('/departments', [OrganizationController::class, 'storeDepartment']);
    Route::patch('/departments/{department}', [OrganizationController::class, 'updateDepartment']);
    Route::post('/sites', [OrganizationController::class, 'storeSite']);
    Route::patch('/sites/{site}', [OrganizationController::class, 'updateSite']);
    Route::post('/members', [OrganizationController::class, 'storeMember']);
    Route::patch('/members/{member}', [OrganizationController::class, 'updateMember']);
});

require __DIR__.'/operator.php';
