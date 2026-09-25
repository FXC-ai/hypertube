<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['movie_id', 'user_id', 'content'])]
class Comment extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('created_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }
}
