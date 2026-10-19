<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('/alunos', 'pages::student.index')->name('alunos.index');
    Route::livewire('/alunos/novo', 'pages::student.create')->name('alunos.create');
});

require __DIR__ . '/settings.php';
