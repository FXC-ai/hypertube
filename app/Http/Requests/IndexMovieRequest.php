<?php

namespace App\Http\Requests;

use App\Enums\MovieSort;
use App\Enums\WatchedFilter;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMovieRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lastMeaningfulYear = Carbon::now()->year + 1;

        return [
            'search' => ['nullable', 'string'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::enum(MovieSort::class)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'watched' => ['nullable', Rule::enum(WatchedFilter::class)],
            'genre' => ['nullable', 'string', 'max:60'],
            'yearFrom' => ['nullable', 'integer', 'min:1870', 'max:'.$lastMeaningfulYear],
            'yearTo' => ['nullable', 'integer', 'min:1870', 'max:'.$lastMeaningfulYear],
            'ratingFrom' => ['nullable', 'numeric', 'between:0,10'],
            'ratingTo' => ['nullable', 'numeric', 'between:0,10'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
