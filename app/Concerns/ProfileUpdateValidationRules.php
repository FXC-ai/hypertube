<?php

namespace App\Concerns;

use App\Enums\Languages;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

trait ProfileUpdateValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        // Log::channel('my_debug')->debug('profileRules est déclenché ', []);

        return [
            'username' => $this->usernameRules($userId),
            'firstname' => $this->firstlastnameRules(),
            'lastname' => $this->firstlastnameRules(),
            'email' => $this->emailRules($userId),
            'preferredlanguage' => $this->preferredlanguageRules()
        ];
    }

    /**
     * Get the validation rules used to validate user usernames.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'sometimes',
            'string',
            'max:255',
            'lowercase',
            $userId === null ? Rule::unique(User::class) : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user firstnames.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function firstlastnameRules(): array
    {
        return ['sometimes', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'sometimes',
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
            'sometimes',
            Rule::enum(Languages::class)
        ];
    }
}
