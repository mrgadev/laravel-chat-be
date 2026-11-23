# Laravel Realtime Live Chat API — Comprehensive Documentation

## 1. Project Overview

This project is a **realtime live chat backend API** built with **Laravel** and **Laravel Reverb** (WebSocket server) for learning and testing event-driven architecture, RESTful endpoints, room membership rules, and realtime broadcasting.

### Key Capabilities

* **Room Management:** Create chat rooms, list rooms along with member roles (`admin` vs. `member`).
* **Message Persistence & Realtime Broadcast:** Sending a message (`POST /api/messages`) both persists the message in the database and immediately broadcasts a `MessageSent` event (`ShouldBroadcastNow`) over a room-scoped public channel (`chat.room.{roomId}`).
* **History + Realtime Sync:** Clients fetch initial message history via `GET /api/rooms/{room}/messages` and subscribe to `chat.room.{roomId}` over WebSocket to receive incoming messages in real time.
* **Optional Sanctum Token Auth:** Supports Laravel Sanctum bearer tokens while allowing flexible `user_id` resolution (`$validated['user_id'] ?? $request->user()?->id`) during testing or incremental adoption.

---

## 2. Architecture Explanation

The system separates **persistent state & business rules (HTTP / MySQL)** from **realtime event fan-out (Laravel Events / Reverb WebSockets)**:

```text
+-------------------+        HTTP REST         +------------------------+
| Client / Postman  | -------------------------> | Laravel API Controllers|
|  (HTTP + WS)      | <------------------------- | (Room, Message, Auth)  |
+-------------------+      JSON Responses        +------------------------+
          |                                           |             |
          | WebSocket (Pusher Protocol)               | Eloquent    | dispatch
          | subscribe: chat.room.{id}                 |             v
          v                                           v         +------------------+
+-------------------+  broadcast payload (JSON)  +---------+ | MessageSent      |
|  Laravel Reverb   | <------------------------- |  MySQL  | | (ShouldBroadcast |
| (WebSocket Srver) |     event: message.sent    +---------+ |  Now)            |
+-------------------+                                          +------------------+

```

### Core Components & Responsibilities

* **Controllers (`RoomController`, `MessageController`, `AuthController`):** Validate incoming payloads, enforce membership/uniqueness rules, query/persist Eloquent models, and trigger broadcast events.
* **Event (`App\Events\MessageSent`):** Implements `ShouldBroadcastNow` to push payloads immediately to Reverb without waiting for a queue worker. Broadcasts on `Channel("chat.room.{$message->room_id}")` as event name `message.sent`.
* **Laravel Reverb:** Acts as the WebSocket server handling client connections, channel subscriptions (`pusher:subscribe`), and broadcasting `message.sent` events to all clients subscribed to a given room channel.
* **Realtime Subscription (`chat.room.{roomId}`):** WebSocket transport concern where multiple connected clients subscribe to the same channel to receive pushed `message.sent` events simultaneously.



---

## 3. Installation & Setup Guide

1. **Create / enter the Laravel project:**
```bash
composer create-project laravel/laravel live-chat-api
cd live-chat-api

```


2. **Install dependencies (Sanctum API & Broadcasting / Reverb):**
```bash
php artisan install:api
php artisan install:broadcasting

```


*(If Reverb is not installed yet, run `composer require laravel/reverb` and `php artisan reverb:install`.)*
3. **Configure `.env` and clear cached config:**
```bash
cp .env.example .env
php artisan key:generate
php artisan config:clear

```


4. **Run database migrations:**
```bash
php artisan migrate

```


5. **Run the application and WebSocket server (in separate terminals):**
```bash
# Terminal 1: HTTP API Server
php artisan serve

# Terminal 2: Reverb WebSocket Server
php artisan reverb:start --debug

```

---

## 4. Environment Configuration

Configure your `.env` file with database, broadcasting, and Reverb credentials:

```env
APP_NAME="Laravel Live Chat API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=live_chat_api
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_CONNECTION=reverb

REVERB_APP_ID=live-chat-app
REVERB_APP_KEY=live-chat-key
REVERB_APP_SECRET=live-chat-secret
REVERB_HOST="127.0.0.1"
REVERB_PORT=8080
REVERB_SCHEME=http

```


---

## 5. Database Schema & Migration Instructions

This architecture uses UUID primary keys (`HasUuids`) across core tables and a custom pivot model (`RoomUser`) so `belongsToMany()->attach()` automatically populates the `id` UUID column on `room_user`.

### Schema Overview

| Table | Purpose | Key Columns | Relationships |
| --- | --- | --- | --- |
| `users` | Stores user accounts | `id` (UUID PK), `name`, `email`, `password`, `timestamps` | Has many `rooms` created (`created_by`), belongs to many `rooms` via `room_user`, has many `messages` |
| `rooms` | Stores chat rooms | `id` (UUID PK), `name`, `description` (nullable), `created_by` (UUID FK -> `users.id`), `timestamps` | Belongs to creator `user` (`created_by`), belongs to many `users` via `room_user`, has many `messages` |
| `room_user` | Pivot table for room membership & roles | `id` (UUID PK), `room_id` (UUID FK), `user_id` (UUID FK), `role` (`admin` / `member`), `last_read_at` (nullable), `timestamps` | Unique composite index on `(room_id, user_id)` |
| `messages` | Stores persisted chat messages | `id` (UUID PK), `room_id` (UUID FK -> `rooms.id`), `user_id` (UUID FK -> `users.id`), `content` (text), `timestamps` | Belongs to `room` and `user` |

### Migration Examples

#### 1. `rooms` Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};

```

#### 2. `room_user` Pivot Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_user');
    }
};

```

#### 3. `messages` Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

```

### Custom Pivot Model (`app/Models/RoomUser.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RoomUser extends Pivot
{
    use HasUuids;

    protected $table = 'room_user';

    public $incrementing = false;

    protected $keyType = 'string';
}

```

Ensure the `belongsToMany` relationship on `Room` and `User` uses `->using(RoomUser::class)` and includes `->withPivot(['role', 'last_read_at'])->withTimestamps()`.

---

## 6. Broadcast & WebSocket Setup

### `MessageSent` Event (`app/Events/MessageSent.php`)

```php
<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel("chat.room.{$this->message->room_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'room_id' => $this->message->room_id,
            'content' => $this->message->content,
            'created_at' => $this->message->created_at?->toISOString(),
            'user' => [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
            ],
        ];
    }
}

```

### Testing Realtime Broadcast in Postman

1. Open a **WebSocket Request** in Postman and connect to:
```text
ws://127.0.0.1:8080/app/live-chat-key?protocol=7&client=postman&version=7.0

```


2. Once **Connected**, send a subscription frame for your target room channel:
```json
{
  "event": "pusher:subscribe",
  "data": {
    "channel": "chat.room.<ROOM_ID>"
  }
}

```


3. Confirm `pusher_internal:subscription_succeeded` is returned.
4. Open additional WebSocket tabs for other simulated clients subscribed to the same `chat.room.<ROOM_ID>`, then send `POST /api/messages` via HTTP. All subscribed clients receive the `message.sent` payload instantaneously.

---

## 7. API Documentation (Requests & Responses)

Base URL: `[http://127.0.0.1:8000/api](http://127.0.0.1:8000/api)`

Common Headers:

* `Accept: application/json`
* `Content-Type: application/json`
* `Authorization: Bearer <TOKEN>` *(required when routes are protected with `auth:sanctum`)*

> **Flexible `user_id` Pattern:** When `user_id` is validated as `nullable` in controllers, `$userId = $validated['user_id'] ?? $request->user()?->id;` uses the explicit payload `user_id` if provided, falling back to the authenticated Sanctum user if omitted.

### 7.1 Register User

* **Endpoint:** `POST /api/register`
* **Request Body:**
```json
{
  "name": "Budi",
  "email": "budi@example.com",
  "password": "secret123"
}

```


* **Response (`201 Created`):**
```json
{
  "message": "Registered successfully",
  "token": "1|laravel_sanctum_token_example",
  "user": {
    "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
    "name": "Budi",
    "email": "budi@example.com"
  }
}

```



### 7.2 Login User

* **Endpoint:** `POST /api/login`
* **Request Body:**
```json
{
  "email": "budi@example.com",
  "password": "secret123"
}

```


* **Response (`200 OK`):**
```json
{
  "message": "Login successful",
  "token": "2|laravel_sanctum_token_example",
  "user": {
    "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
    "name": "Budi",
    "email": "budi@example.com"
  }
}

```



### 7.3 List Rooms with Member Roles

* **Endpoint:** `GET /api/rooms`
* **Response (`200 OK`):**
```json
{
  "data": [
    {
      "id": "01a2072f-3316-7353-9c04-4b2444a96051",
      "name": "Laravel Study Room",
      "description": "Testing realtime events",
      "members_count": 2,
      "created_by": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
      "creator": {
        "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
        "name": "Budi"
      },
      "members": [
        {
          "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
          "name": "Budi",
          "role": "admin",
          "joined_at": "2026-11-23T06:54:23.000000Z"
        },
        {
          "id": "01a2072f-1111-7aaa-87be-aae26e1d9999",
          "name": "Siti",
          "role": "member",
          "joined_at": "2026-11-23T07:01:10.000000Z"
        }
      ],
      "created_at": "2026-11-23T06:54:23.000000Z"
    }
  ]
}

```



### 7.4 Create Room (Creator Assigned `admin` Role)

* **Endpoint:** `POST /api/rooms`
* **Request Body:**
```json


```


* **Response (`201 Created`):**
```json
{
  "message": "Room created successfully",
  "data": {
    "id": "01a2072f-3316-7353-9c04-4b2444a96051",
    "name": "Laravel Study Room",
    "description": "Testing realtime events",
    "created_by": "01a2072f-0c92-7a6a-87be-aae26e1d1f39"
  }
}

```



### 7.5 Get Room Messages

* **Endpoint:** `GET /api/rooms/{room}/messages`
* **Response (`200 OK`):**
```json
{
  "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
  "data": [
    {
      "id": "01a2072f-9999-7bbb-87be-aae26e1d1234",
      "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
      "user_id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
      "content": "Hello everyone!",
      "created_at": "2026-11-23T07:05:00.000000Z",
      "user": {
        "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
        "name": "Budi"
      }
    }
  ]
}

```



### 7.6 Send Message & Broadcast Event

* **Endpoint:** `POST /api/rooms/{room}/messages`
* **Request Body:**
```json
{
  "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
  "user_id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
  "content": "Hello everyone!"
}

```


* **HTTP Response (`201 Created`):**
```json
{
  "message": "Message sent and broadcasted",
  "data": {
    "id": "01a2072f-9999-7bbb-87be-aae26e1d1234",
    "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
    "user_id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
    "content": "Hello everyone!",
    "user": {
      "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
      "name": "Budi"
    }
  }
}

```


* **WebSocket Event Payload (`message.sent` on `chat.room.{roomId}`):**
```json
{
  "id": "01a2072f-9999-7bbb-87be-aae26e1d1234",
  "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
  "content": "Hello everyone!",
  "created_at": "2026-11-23T07:05:00.000000Z",
  "user": {
    "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
    "name": "Budi"
  }
}

```
