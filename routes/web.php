<?php

use App\Http\Controllers\Settings\ProfilePictureController;
use Illuminate\Support\Facades\Route;

Route::inertia('/listmovies', 'listmovies/index')->name('test');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::patch('/updateavatar', [ProfilePictureController::class, 'update'])->name('update.avatar');
});

require __DIR__ . '/settings.php';
