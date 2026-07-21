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
    Route::patch('employees/{employee}/status', [EmployeeController::class, 'status'])->name('employees.status');
    Route::post('employees/{employee}/separation', [EmployeeController::class, 'separate'])->name('employees.separate');
    Route::post('employees/{employee}/transfer', [EmployeeController::class, 'transfer'])->name('employees.transfer');
    Route::post('employees/{employee}/documents', [EmployeeController::class, 'uploadDocument'])->name('employees.documents.store');
    Route::delete('employees/{employee}/documents/{attachment}', [EmployeeController::class, 'destroyDocument'])->name('employees.documents.destroy');
});
