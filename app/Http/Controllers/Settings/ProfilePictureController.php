<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfilepictureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Log;


class ProfilePictureController extends Controller
{
    /**
     * Update the avatar for the user.
     */
    public function update(ProfilepictureRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        $previousProfilepicture = $user->profilepicture;

        $newPath = $validated['profilepicture']->store('avatars', 'public');

        Log::channel('my_debug')->debug('newPath = ', [$newPath]);
        // https://laravel.com/framework/docs/13.x/images

        $user->update(['profilepicture' => $newPath]);

        if ($previousProfilepicture != null) {
            Storage::disk('public')->delete($previousProfilepicture);
        }

        return to_route('profile.edit');
    }
}
