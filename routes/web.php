<?php

use App\Modules\Foundation\Http\Controllers\BusinessSwitcherController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('businesses/switch', [BusinessSwitcherController::class, 'index'])->name('businesses.switch');
    Route::post('businesses/switch', [BusinessSwitcherController::class, 'store'])->name('businesses.switch.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
