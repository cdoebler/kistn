<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.edit')->name('projects.edit');
    Route::livewire('projects/{project}/packages', 'pages::projects.packages')->name('projects.packages');
});

require __DIR__.'/settings.php';
