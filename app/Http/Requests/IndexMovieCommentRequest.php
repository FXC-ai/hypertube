<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMovieCommentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(['created_at'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1']
        ];
    }
}
