<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Every authenticated user may read anyone's profile, the email address aside.
     */
    public function view(User $user, User $model): bool
    {
        return true;
    }

    /**
     * A profile is only ever editable by the user it belongs to.
     */
    public function update(User $user, User $model): bool
    {
        return $user->id === $model->id;
    }
}
