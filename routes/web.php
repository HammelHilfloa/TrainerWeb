<?php
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('mobile', [
        'title' => 'Dashboard',
        'content' => 'Willkommen! Bitte melde dich an, um Trainings zu verwalten.'
    ]);
})->name('landing');

Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'mobile', [
        'title' => 'Dein Dashboard',
        'content' => 'Übersicht deiner kommenden Trainings, Turniere und Abrechnungen.'
    ])->name('dashboard');

    Route::prefix('trainings')->group(function () {
        Route::get('/', 'TrainingSessionController@index')->name('trainings.index');
        Route::get('/{session}', 'TrainingSessionController@show')->name('trainings.show');
        Route::post('/', 'TrainingSessionController@store')
            ->middleware('can:create,App\\Models\\TrainingSession');
        Route::put('/{session}', 'TrainingSessionController@update')
            ->middleware('can:update,session');
        Route::put('/{session}/plan', 'TrainingSessionController@updatePlan')
            ->name('trainings.plan')
            ->middleware(['can:update,session', 'role:admin']);
        Route::delete('/{session}', 'TrainingSessionController@destroy')
            ->middleware(['can:delete,session', 'role:admin']);
    });

    Route::prefix('einteilung')->group(function () {
        Route::post('/{session}/assign', 'TrainingAssignmentController@assignSelf');
        Route::post('/{session}/cancel', 'TrainingAssignmentController@cancel');
    });

    Route::prefix('abwesenheit')->group(function () {
        Route::get('/', 'TrainerAvailabilityController@index');
        Route::post('/', 'TrainerAvailabilityController@store');
    });

    Route::prefix('turniere')->group(function () {
        Route::get('/', 'TournamentController@index');
        Route::post('/', 'TournamentController@store');
        Route::post('/{tournament}/assign', 'TournamentAssignmentController@assign');
    });

    Route::prefix('abrechnung')->group(function () {
        Route::post('/halbjahr', 'InvoiceController@generateHalfYear');
        Route::get('/{trainer}/export', 'InvoiceController@export');
    });
});
