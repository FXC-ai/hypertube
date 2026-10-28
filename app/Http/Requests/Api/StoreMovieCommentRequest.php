<?php

namespace App\Http\Requests\Api;

use App\Models\Movie;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMovieCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The movie is the one the route points to; a `movie_id` in the payload is only
     * accepted when it names that same movie.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $movie = $this->route('movie');

        return [
            'comment' => ['required', 'string', 'min:1', 'max:5000'],
            'movie_id' => ['sometimes', 'integer', 'in:'.($movie instanceof Movie ? $movie->id : 0)],
        ];
    }
}
