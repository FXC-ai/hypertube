<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\AnonymousComponent;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function show(User $user): Response
    {
        $publicUserResource = new PublicUserResource($user);

        // Log::channel('my_debug')->debug('verificationUrl ', [$publicUserResource]);

        // return new PublicUserResource($user);

        return Inertia::render(
            'users/show',
            [
                "user" => $publicUserResource,
            ]
        );
    }

    public function index(IndexUserRequest $request): Response
    {

        $params = $request->validated();

        $search = $params['search'] ?? null;
        $sort = $params['sort'] ?? null;
        $dir = $params['dir'] ?? 'asc';
        $perPage = (int) ($params['perPage'] ?? 5);

        Log::channel("my_debug")->debug("search = ", [$search]);
        // $page = (int) ($params['page'] ?? 1);

        $query = User::query();

        if ($search) {
            $query->where('username', 'like', '%' . $search . '%');
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        // $users = $query->paginate($perPage);

        // Log::channel('my_debug')->debug('List USers ', [$users]);

        return Inertia::render(
            'users/index',
            [
                "users" => Inertia::scroll(
                    fn() => PublicUserResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                ],
            ]
        );
    }
}
