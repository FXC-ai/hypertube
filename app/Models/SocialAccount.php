<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(
    [
        'user_id',
        'provider',
        'provider_id',
        'avatar',
        'token',
        'refresh_token',
        'token_expires_at',
    ]
)]

/**
 * The link between an account of this instance and the provider it signed in with.
 *
 * @property-read User $user
 */
class SocialAccount extends Model
{
    /*
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }
    */

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
