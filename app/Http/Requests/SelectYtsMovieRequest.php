<?php

namespace App\Http\Requests;

use App\Enums\MovieSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SelectYtsMovieRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && in_array(MovieSource::Yts, $this->user()->enabledSources(), true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'imdb_code' => ['required', 'string', 'regex:/^tt\d{6,10}$/'],
        ];
    }
}
