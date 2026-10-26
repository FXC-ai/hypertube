<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\MovieConversionController;
use App\Http\Controllers\Settings\ProfilePictureController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->whereIn('provider', ['github', 'fortytwo'])->name('socialite.redirect');
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->whereIn('provider', ['github', 'fortytwo'])->name('socialite.callback');


Route::middleware(['auth', 'verified'])->group(function () {

    Route::patch('/updateavatar', [ProfilePictureController::class, 'update'])->name('update.avatar');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

    Route::get('/movies/{movie}', [MovieController::class, 'show'])->name('movies.show');
    Route::get('/movies', [MovieController::class, 'index'])->name('movies.index');

    Route::post('/movies/{movie}/conversion', [MovieConversionController::class, 'store'])->name('movies.conversion.store');
    Route::get('/movies/{movie}/conversion', [MovieConversionController::class, 'show'])->name('movies.conversion.show');

    Route::get('/movies/{movie}/comments', [CommentController::class, 'index'])->name('movies.comments.index');
    Route::post('/movies/{movie}/comments', [CommentController::class, 'store'])->name('movies.comments.store');

    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});

require __DIR__ . '/settings.php';
