<?php

use App\Livewire\Dashboard;
use App\Livewire\Health;
use App\Livewire\Pairs;
use App\Livewire\Recipients;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('pairs', Pairs\Index::class)->name('pairs.index');
    Route::livewire('pairs/create', Pairs\Form::class)->name('pairs.create');
    // Registered after /pairs/create so the literal segment wins.
    Route::livewire('pairs/{pair}', Pairs\Show::class)->name('pairs.show');
    Route::livewire('pairs/{pair}/edit', Pairs\Form::class)->name('pairs.edit');

    Route::livewire('recipients', Recipients\Index::class)->name('recipients.index');

    // Not a page about the pairs: a page about how something else watches this.
    Route::livewire('health', Health\Index::class)->name('health.index');
});

require __DIR__.'/settings.php';
