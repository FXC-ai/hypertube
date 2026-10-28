<?php

namespace App\Http\Requests\Api;

use App\Concerns\ProfileUpdateValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use ProfileUpdateValidationRules;

    /**
     * Any authenticated user may patch the profile the route points to; the policy
     * narrows that down to the owner of the profile.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $profile = $this->route('user');

        return array_merge(
            $this->profileRules($profile instanceof User ? $profile->id : null),
            [
                'password' => ['sometimes', 'string', Password::default()],
                'profilepicture' => ['sometimes', 'string', 'url', 'max:2048'],
            ],
        );
    }

    /**
     * A PATCH carrying nothing is not an update.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('payload', __('At least one field must be provided.'));
            }
        });
    }
}
