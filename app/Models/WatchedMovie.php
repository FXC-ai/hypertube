<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $watched_at
 */
#[Fillable(['movie_id', 'user_id', 'watched_at'])]
class WatchedMovie extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'watched_at' => 'datetime',
        ];
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function markWatched(User $user, Movie $movie): self
    {
        $movie->last_watched_at = now();
        $movie->save();
        return static::firstOrCreate(
            ['movie_id' => $movie->id, 'user_id' => $user->id],
            ['watched_at' => Carbon::now()],
        );
    }
}
