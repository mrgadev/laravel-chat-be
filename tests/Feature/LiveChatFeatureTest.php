<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_join_room_as_member(): void
    {
        $creator = User::factory()->create(['name' => 'Budi']);
        $member = User::factory()->create(['name' => 'Siti']);

        $room = Room::create([
            'name' => 'Ruang Diskusi',
            'description' => 'Test join',
            'capacity' => 10,
            'created_by' => $creator->id,
        ]);
        $room->users()->attach($creator->id, ['role' => 'admin']);

        $response = $this->postJson("/api/rooms/{$room->id}/join", [
            'user_id' => $member->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user_id', $member->id)
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('room_user', [
            'room_id' => $room->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_duplicate_join_room_is_rejected(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $room = Room::create([
            'name' => 'Ruang Duplikat',
            'capacity' => 10,
            'created_by' => $creator->id,
        ]);
        $room->users()->attach($creator->id, ['role' => 'admin']);
        $room->users()->attach($member->id, ['role' => 'member']);

        $response = $this->postJson("/api/rooms/{$room->id}/join", [
            'user_id' => $member->id,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'User is already a member of this room',
            ]);

        $this->assertCount(2, $room->fresh()->users);
    }


    public function test_non_member_cannot_send_message_and_no_event_is_broadcasted(): void
    {
        Event::fake([MessageSent::class]);

        $creator = User::factory()->create();
        $outsider = User::factory()->create();

        $room = Room::create([
            'name' => 'Ruang Privat Member',
            'capacity' => 10,
            'created_by' => $creator->id,
        ]);
        $room->users()->attach($creator->id, ['role' => 'admin']);

        $response = $this->postJson('/api/messages', [
            'room_id' => $room->id,
            'user_id' => $outsider->id,
            'content' => 'Pesan dari luar room',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'User must join the room before sending messages',
            ]);

        $this->assertDatabaseMissing('messages', [
            'room_id' => $room->id,
            'user_id' => $outsider->id,
            'content' => 'Pesan dari luar room',
        ]);

        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_member_sending_message_persists_and_broadcasts_event(): void
    {
        Event::fake([MessageSent::class]);

        $creator = User::factory()->create(['name' => 'Budi']);
        $member = User::factory()->create(['name' => 'Siti']);

        $room = Room::create([
            'name' => 'Ruang Realtime',
            'capacity' => 10,
            'created_by' => $creator->id,
        ]);
        $room->users()->attach($creator->id, ['role' => 'admin']);
        $room->users()->attach($member->id, ['role' => 'member']);

        $response = $this->postJson('/api/messages', [
            'room_id' => $room->id,
            'user_id' => $member->id,
            'content' => 'Halo semuanya!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.content', 'Halo semuanya!')
            ->assertJsonPath('data.user.id', $member->id);

        $this->assertDatabaseHas('messages', [
            'room_id' => $room->id,
            'user_id' => $member->id,
            'content' => 'Halo semuanya!',
        ]);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($room, $member) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($channel) => $channel->name, $channels);

            return $event->message->room_id === $room->id
                && $event->message->user_id === $member->id
                && $event->message->content === 'Halo semuanya!'
                && in_array("chat.room.{$room->id}", $channelNames, true)
                && $event->broadcastAs() === 'message.sent';
        });
    }
}
