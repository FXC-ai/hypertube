<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileUpdateValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileUpdateValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        return $this->profileRules($this->user()->id);
    }
}
