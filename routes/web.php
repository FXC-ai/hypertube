<?php

use App\Http\Controllers\Settings\ProfilePictureController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SocialiteController;

Route::inertia('/listmovies', 'listmovies/index')->name('test');
Route::inertia('/dashboard2', 'dashboard2')->name('dashboard2');
Route::inertia('/', 'welcome')->name('home');


Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('socialite.redirect');
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('socialite.callback');
Route::whereIn('provider', ['github', 'fortytwo']);

Route::middleware(['auth', 'verified'])->group(function () {

    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::patch('/updateavatar', [ProfilePictureController::class, 'update'])->name('update.avatar');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
});

require __DIR__ . '/settings.php';
