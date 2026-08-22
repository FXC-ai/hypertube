<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfilepictureRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

use Illuminate\Support\Facades\Log;


class ProfilepictureRequest extends FormRequest
{
    use ProfilepictureRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        $test = ['profilepicture' => $this->profilepictureRules()];


        return $test;
    }
}
