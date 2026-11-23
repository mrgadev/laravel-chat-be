<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Models\Message;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Room $room): JsonResponse
    {
   
        $messages = $room->messages()
            ->with('user:id,name')
            ->oldest()
            ->get();

        return response()->json([
            'room_id' => $room->id,
            'data' => $messages,
        ]);
    }

    public function store(SendMessageRequest $request, Room $room): JsonResponse
    {
        $data = $request->validated();

        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $data['user_id'],
            'content' => $data['content'],
        ]);

        $message->load('user:id,name');

        // Broadcast ke semua client yang subscribe ke channel room ini
        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'message' => 'Message sent and broadcasted',
            'data' => $message,
        ], 201);
    }
}
