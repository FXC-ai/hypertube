<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\AnonymousComponent;

class UserController extends Controller
{
    public function show(User $user): PublicUserResource
    {
        $publicUserResource = new PublicUserResource($user);

        // Log::channel('my_debug')->debug('verificationUrl ', [$publicUserResource]);

        return new PublicUserResource($user);
    }

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $params = $request->validated();

        $search = $params['search'] ?? null;
        $sort = $params['sort'] ?? null;
        $dir = $params['dir'] ?? 'asc';
        $perPage = (int) ($params['perPage'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $query = User::query();

        if ($search) {
            $query->where('username', 'like', '%' . $search . '%');
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        }

        $users = $query->paginate($perPage);

        // Log::channel('my_debug')->debug('List USers ', [$users]);

        return PublicUserResource::collection($users);
    }
}
