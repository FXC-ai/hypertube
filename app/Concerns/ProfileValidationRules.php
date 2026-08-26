<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use App\Enums\Languages;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'username' => $this->usernameRules(),
            'firstname' => $this->firstlastnameRules(),
            'lastname' => $this->firstlastnameRules(),
            'email' => $this->emailRules($userId),
            'preferredlanguage' => $this->preferredlanguageRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user usernames.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function usernameRules(): array
    {
        return ['required', 'lowercase', 'string', 'max:255', Rule::unique(User::class)];
    }



    /**
     * Get the validation rules used to validate user firstnames.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function firstlastnameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null ? Rule::unique(User::class) : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user preferred language.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function preferredlanguageRules(): array
    {
        return [
            Rule::enum(Languages::class)
        ];
    }
}
