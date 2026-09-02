<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;



#[Fillable(['title', 'filepath', 'filename'])]
class Movie extends Model
{
    use HasFactory;
}
