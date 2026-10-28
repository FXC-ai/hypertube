<?php

namespace App\Http\Requests\Settings;

use App\Enums\MovieSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SourceToggleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowed = array_map(fn(MovieSource $source): string => $source->value, MovieSource::catalogSources());

        return [
            'sources' => ['required', 'array:' . implode(',', $allowed)],
            'sources.*' => ['boolean'],
        ];
    }
}
