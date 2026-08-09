<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('can:projects.view')->group(function () {
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');

        Route::livewire('projects/create', 'pages::projects.create')
            ->middleware('can:projects.create')
            ->name('projects.create');

        Route::livewire('projects/{project}/edit', 'pages::projects.edit')
            ->middleware('can:projects.edit-details')
            ->name('projects.edit');
    });
});

require __DIR__.'/settings.php';
