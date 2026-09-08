<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'filepath',
    'filename',
    'conversion_status',
    'conversion_attempt',
    'conversion_error',
    'conversion_started_at',
    'conversion_playable_at',
    'conversion_completed_at',
])]
class Movie extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'conversion_status' => ConversionStatus::class,
            'conversion_started_at' => 'datetime',
            'conversion_playable_at' => 'datetime',
            'conversion_completed_at' => 'datetime',
        ];
    }

    public function isPlayable(): bool
    {
        return in_array($this->conversion_status, [
            ConversionStatus::Playable,
            ConversionStatus::Converted,
        ], true);
    }
}
