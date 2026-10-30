<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function show(User $user): Response
    {
        return Inertia::render(
            'users/show',
            [
                'user' => new PublicUserResource($user),
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

        $query = User::query();

        if ($search) {
            $query->where('username', 'like', '%'.$search.'%');
        }

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        return Inertia::render(
            'users/index',
            [
                'users' => Inertia::scroll(
                    fn () => PublicUserResource::collection($query->paginate($perPage))
                ),
                'filters' => [
                    'search' => $search,
                ],
            ]
        );
    }
}
