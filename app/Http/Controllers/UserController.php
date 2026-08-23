<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function show(User $user): PublicUserResource
    {
        $publicUserResource = new PublicUserResource($user);

        // Log::channel('my_debug')->debug('verificationUrl ', [$publicUserResource]);

        return new PublicUserResource($user);
    }
}
