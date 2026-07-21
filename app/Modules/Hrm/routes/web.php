<?php

use App\Modules\Hrm\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    // Each action authorizes hrm.employees.manage internally; {employee} is a
    // central record, confined to the current business in the controller.
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
});
