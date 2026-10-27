<?php

namespace App\Models;

use App\Enums\Languages;
use App\Enums\MovieSource;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, bool>|null $sources
 */
#[Fillable(['username', 'email', 'firstname', 'lastname', 'password', 'preferredlanguage', 'profilepicture', 'sources'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferredlanguage' => Languages::class,
            'sources' => 'array',
        ];
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * The movies this user has already watched.
     *
     * @return HasManyThrough<Movie, WatchedMovie>
     */
    public function watchedMovies(): HasManyThrough
    {
        return $this->hasManyThrough(Movie::class, WatchedMovie::class);
    }

    public function hasWatched(Movie $movie): bool
    {
        return WatchedMovie::where('user_id', $this->id)
            ->where('movie_id', $movie->id)
            ->exists();
    }

    /**
     * Catalog sources this user is allowed to query, intersected with the sources
     * globally enabled in the configuration.
     *
     * @return list<MovieSource>
     */
    public function enabledSources(): array
    {
        $preferences = MovieSource::defaultPreferences();

        $sources = is_array($this->sources) ? $this->sources : [];

        foreach ($sources as $source => $enabled) {
            $preferences[$source] = (bool) $enabled;
        }

        return array_values(array_filter(
            MovieSource::catalogSources(),
            fn(MovieSource $source): bool => $preferences[$source->value]
                && (bool) config("services.sources.{$source->value}.enabled", true),
        ));
    }
}
