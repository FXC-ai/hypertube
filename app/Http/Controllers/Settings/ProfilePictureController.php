<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfilepictureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Log;


class ProfilePictureController extends Controller
{
    /**
     * Update the avatar for the user.
     */
    public function update(ProfilepictureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        $previousProfilepicture = $user->profilepicture;

        $newPath = $validated['profilepicture']->store('avatars', 'public');
        // https://laravel.com/framework/docs/13.x/images

        $user->update(['profilepicture' => $newPath]);

        if ($previousProfilepicture != null) {
            Storage::disk('public')->delete($previousProfilepicture);
        }

        return response()->json(['path' => $newPath]);
    }
}
