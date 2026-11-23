<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRoomRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RoomController extends Controller
{
    /**
     * GET /api/rooms
     * Menampilkan daftar room beserta user yang membuat/bergabung dan role-nya.
     */
    public function index(): JsonResponse
    {
        $rooms = Room::query()
            ->with([
                'creator:id,name',
                'users:id,name',
            ])
            ->latest()
            ->get()
            ->map(function (Room $room) {
                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'description' => $room->description,
                    'members_count' => $room->users->count(),
                    'creator' => $room->creator,
                    'members' => $room->users->map(function (User $user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'role' => $user->pivot->role, // 'admin' or 'member'
                            'joined_at' => $user->pivot->created_at?->toISOString(),
                        ];
                    })->values(),
                    'created_at' => $room->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'data' => $rooms,
        ]);
    }

    /**
     * POST /api/rooms
     * Create room and register its creator as an admin using pivot room_user.
     */
    public function store(CreateRoomRequest $request): JsonResponse
    {
        $data = $request->validated();

        $room = Room::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => Arr::get($data, 'user_id') ?? $request->user()->id,
        ]);

        $room->users()->attach($data['user_id'], [
            'role' => 'admin',
        ]);

        $room->load(['creator:id,name', 'users:id,name']);

        return response()->json([
            'message' => 'Room created successfully',
            'data' => $room,
        ], 201);
    }

    /**
     * POST /api/rooms/{room}/join
     * User can join into certain room with default role as member
     */
    public function join(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $userId = $data['user_id'] ?? $request->user()->id;;

        // 1. Check if user already joined room
        $alreadyJoined = $room->users()
            ->where('users.id', $userId)
            ->exists();

        if ($alreadyJoined) {
            return response()->json([
                'message' => 'User is already a member of this room',
            ], 422);
        }

        // 3. Insert user into pivot with role 'member'
        $room->users()->attach($userId, [
            'role' => 'member',
        ]);

        $member = $room->users()
            ->where('users.id', $userId)
            ->first();

        return response()->json([
            'message' => 'Joined room successfully',
            'data' => [
                'room_id' => $room->id,
                'user_id' => $member->id,
                'name' => $member->name,
                'role' => $member->pivot->role,
            ],
        ], 201);
    }
}
