<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


#[Fillable(
    [
        'user_id',
        'provider',
        'provider_id',
        'avatar',
        'token',
        'refresh_token',
        'token_expires_at'
    ]
)]

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
