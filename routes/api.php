<?php

use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
 * The RESTful API documented at /api/documentation. An unknown path answers 404 and an
 * unsupported method answers 405, so nothing outside these routes is usable.
 */

Route::get('/', [MovieController::class, 'describe'])->name('api.describe');

Route::get('/movies', [MovieController::class, 'index'])->name('api.movies.index');

/*
 * Ids are bigint columns: anything else must answer 404, not reach PostgreSQL, which rejects
 * "abc" or 99999999999999999999 with an error (500). 18 digits always fit in a bigint.
 */
Route::middleware('auth:api')->where([
    'movie' => '[0-9]{1,18}',
    'user' => '[0-9]{1,18}',
    'comment' => '[0-9]{1,18}',
])->group(function (): void {
    Route::get('/movies/{movie}', [MovieController::class, 'show'])->name('api.movies.show');

    Route::get('/users', [UserController::class, 'index'])->name('api.users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('api.users.show');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('api.users.update');

    Route::get('/comments', [CommentController::class, 'index'])->name('api.comments.index');
    Route::get('/comments/{comment}', [CommentController::class, 'show'])->name('api.comments.show');
    Route::post('/comments', [CommentController::class, 'store'])->name('api.comments.store');
    Route::patch('/comments/{comment}', [CommentController::class, 'update'])->name('api.comments.update');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('api.comments.destroy');

    Route::get('/movies/{movie}/comments', [CommentController::class, 'indexForMovie'])->name('api.movies.comments.index');
    Route::post('/movies/{movie}/comments', [CommentController::class, 'storeForMovie'])->name('api.movies.comments.store');
});
