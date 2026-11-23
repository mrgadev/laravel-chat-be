<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'] ?? str()->random(16),
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user->only(['id', 'name', 'email', 'created_at']),
        ], 201);
    }
}
