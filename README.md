# Live Chat MVP (Laravel + Reverb) — Comprehensive Documentation

## 1. Project Overview

This project is a **real-time Live Chat MVP** built with **Laravel** and **Laravel Reverb** for learning RESTful API design, relational database modeling/pivot relationships, event broadcasting, and WebSocket room subscriptions.

### Key Characteristics & Scope

* **Learning-Focused MVP**: Designed to demonstrate how HTTP endpoints and WebSocket events work together without overcomplicating early iterations.
* **No Authentication Initially**: Users and room memberships are referenced via `user_id` and `room_id` (UUIDs) to keep testing straightforward in tools like Postman.
* **Public Channel Broadcasting**: Uses public broadcast channels (`chat.room.{roomId}`) over the Pusher protocol via Laravel Reverb.
* **Immediate Message Delivery**: Creating a message via `POST /api/messages` immediately persists it to the database and dispatches a `MessageSent` (`ShouldBroadcastNow`) event to connected subscribers.
* **Room Membership & Capacity Rules**: Creators are assigned the `admin` role in the pivot table; joining users receive the `member` role, subject to duplicate checks and room `capacity` limits.

---

## 2. Architecture Explanation

The system separates **persistent state & business rules (HTTP REST API)** from **real-time fan-out delivery (Laravel Events + Reverb WebSocket server)**.

### Core Components

1. **REST API Layer (`routes/api.php` & Controllers)**:
* `RoomController`: Handles listing rooms (with creator and member roles), creating rooms (assigning creator as `admin`), and joining rooms (enforcing duplicate and `capacity` rules).
* `MessageController`: Handles fetching room message history (`GET /api/rooms/{room}/messages`) and sending new messages (`POST /api/messages`) after verifying room membership.


2. **Database & Eloquent Layer**:
* `User`, `Room`, `Message`, and custom pivot `RoomUser` models manage relationships, UUID keys, and pivot metadata (`role`, `last_read_at`).


3. **Event & Broadcasting Layer**:
* `MessageSent` implements `ShouldBroadcastNow`, targets `Channel("chat.room.{$message->room_id}")`, names the event `message.sent`, and shapes the broadcast payload via `broadcastWith()`.


4. **WebSocket Server Layer (Laravel Reverb)**:
* Maintains active WebSocket connections from clients (e.g., Postman WebSocket tabs or browser clients), processes `pusher:subscribe` frames for `chat.room.{roomId}`, and pushes `message.sent` payloads to all active subscribers on that channel.



### Typical Client Flow

1. **Membership**: User creates a room (`POST /api/rooms` → `role: admin`) or joins an existing room (`POST /api/rooms/{room}/join` → `role: member`).
2. **Initial History**: Client fetches existing messages via `GET /api/rooms/{room}/messages`.
3. **Real-Time Subscription**: Client connects to the Reverb WebSocket endpoint and subscribes to `chat.room.{roomId}`.
4. **Send & Broadcast**: A member calls `POST /api/messages`. Laravel stores the record in `messages` and broadcasts `MessageSent` to Reverb, which immediately delivers it to all clients subscribed to `chat.room.{roomId}`.

*(Note: `POST /api/rooms/{room}/join` manages database membership and authorization rules—it does not open or subscribe a WebSocket connection on its own.)*

---

## 3. Installation & Setup Guide

1. **Install PHP & Laravel Dependencies**
Ensure PHP and Composer are installed, then install project dependencies:
```bash
composer install

```


2. **Install Broadcasting & Laravel Reverb**
Scaffold broadcasting configuration and install Laravel Reverb:
```bash
php artisan install:broadcasting

```


If Reverb is not yet installed, ensure the package is present and initialized:
```bash
composer require laravel/reverb
php artisan reverb:install

```


3. **Generate Application Key & Clear Caches**
```bash
php artisan key:generate
php artisan config:clear

```


4. **Run Database Migrations**
```bash
php artisan migrate

```


5. **Run Development & WebSocket Servers (Two Terminals)**
* **Terminal 1 (Laravel HTTP API):**
```bash
php artisan serve

```


* **Terminal 2 (Laravel Reverb WebSocket Server):**
```bash
php artisan reverb:start --debug

```




6. **Run Automated Feature Tests (Optional)**
```bash
php artisan test --filter=LiveChatFeatureTest

```



---

## 4. Environment Configuration

Configure your `.env` file with your database credentials, broadcast driver, and Reverb settings:

```env
APP_NAME="Live Chat MVP"
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

REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST="127.0.0.1"
REVERB_PORT=8080
REVERB_SCHEME=http

```

Whenever you modify `.env`, run:

```bash
php artisan config:clear

```

---

## 5. Database Migration Instructions

Ensure your migrations define UUID primary/foreign keys consistently across tables and handle the `room_user` pivot primary key properly so `attach()` does not fail with missing `id` defaults.

### Pivot Key Design (`room_user`)

* **Custom Pivot Model with `HasUuids` (`app/Models/RoomUser.php`)**: If `room_user` includes a UUID `id` column (`$incrementing = false`, `$keyType = 'string'`), bind it to the `belongsToMany` relationship via `->using(RoomUser::class)` so Laravel automatically generates UUIDs on `attach()`.
* **Composite Primary Key Alternative**: If `room_user` does not need a separate `id` column, define `$table->primary(['room_id', 'user_id']);` in the `room_user` migration instead.

### Running & Resetting Migrations

```bash
# Run pending migrations
php artisan migrate

# Fresh migrate during local MVP development (destroys existing data)
php artisan migrate:fresh

```

---

## 6. Database Schema Explanation

| Table | Key Columns | Purpose & Relationships |
| --- | --- | --- |
| `users` | `id` (UUID PK), `name`, `email`, `password`, `timestamps` | Stores user records. Has many created rooms (`created_by`), many joined rooms via `room_user`, and many sent `messages`. |
| `rooms` | `id` (UUID PK), `name`, `description` (nullable), `capacity` (nullable/int), `created_by` (UUID FK → `users.id`), `timestamps` | Represents chat rooms. Belongs to a creator (`User`) via `created_by`, has many members (`User`) via `room_user`, and has many `messages`. |
| `room_user` | `id` (UUID PK, if using `RoomUser` pivot) or composite PK (`room_id`, `user_id`), `room_id` (UUID FK → `rooms.id`), `user_id` (UUID FK → `users.id`), `role` (`admin` | `member`), `last_read_at` (nullable timestamp), `timestamps` | Pivot table linking `users` and `rooms`. Tracks each participant's `role` (`admin` for room creator, `member` for joined users) and enforces unique membership per room. |
| `messages` | `id` (UUID PK), `room_id` (UUID FK → `rooms.id`), `user_id` (UUID FK → `users.id`), `body` (text), `timestamps` | Stores chat message history. Belongs to one `Room` and one `User` (sender). |

---

## 7. Broadcast / WebSocket Setup

### Event Configuration (`app/Events/MessageSent.php`)

* Implements `ShouldBroadcastNow` so events are pushed immediately to Reverb without waiting for a queue worker.
* `broadcastOn()` returns `[new Channel("chat.room.{$this->message->room_id}")]`.
* `broadcastAs()` returns `'message.sent'`.
* `broadcastWith()` broadcasts a structured payload containing `id`, `room_id`, `body`, `created_at`, and `user` (`id`, `name`).

### Channel Authorization (`routes/channels.php`)

Because `chat.room.{roomId}` uses a **public channel** (`Channel`) for this unauthenticated MVP stage, no private/presence channel auth callback is required for subscription.

### Connecting & Subscribing in Postman

1. Open a **WebSocket** request tab in Postman.
2. Connect to:
```text
ws://127.0.0.1:8080/app/my-app-key?protocol=7&client=postman&version=7.0

```


*(Replace `my-app-key`, host, and port with your `.env` values.)*
3. Once **Connected**, send a Pusher protocol subscription frame (replace `<ROOM_ID>` with a valid room UUID):
```json
{
  "event": "pusher:subscribe",
  "data": {
    "channel": "chat.room.<ROOM_ID>"
  }
}

```


4. Confirm `pusher_internal:subscription_succeeded` is received. Trigger `POST /api/messages` from an HTTP tab to observe `message.sent` arrive in real time across all subscribed WebSocket clients.

---

## 8. API Documentation (With Request & Response Examples)

Base URL: `[http://127.0.0.1:8000/api](http://127.0.0.1:8000/api)`

Recommended header for JSON validation errors: `Accept: application/json`

### 8.1 List Rooms

* **Endpoint:** `GET /api/rooms`
* **Description:** Returns rooms along with creator info, member count, and each member's `role` (`admin` or `member`).
* **Example Response (`200 OK`):**
```json
{
  "data": [
    {
      "id": "01a2072f-3316-7353-9c04-4b2444a96051",
      "name": "Laravel Discussion",
      "description": "Learning events and APIs",
      "capacity": 20,
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



### 8.2 Create Room

* **Endpoint:** `POST /api/rooms`
* **Description:** Creates a new room and attaches `user_id` to `room_user` with `role = admin`.
* **Example Request Body:**
```json
{
  "name": "Laravel Discussion",
  "description": "Learning events and APIs",
  "capacity": 20,
  "user_id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39"
}

```


* **Example Response (`201 Created`):**
```json
{
  "message": "Room created successfully",
  "data": {
    "id": "01a2072f-3316-7353-9c04-4b2444a96051",
    "name": "Laravel Discussion",
    "description": "Learning events and APIs",
    "capacity": 20,
    "created_by": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
    "created_at": "2026-11-23T06:54:23.000000Z",
    "updated_at": "2026-11-23T06:54:23.000000Z"
  }
}

```



### 8.3 Join Room

* **Endpoint:** `POST /api/rooms/{room}/join`
* **Description:** Adds a user to the room as `member` after checking for duplicate membership and room `capacity`.
* **Example Request Body:**
```json
{
  "user_id": "01a2072f-1111-7aaa-87be-aae26e1d9999"
}

```


* **Example Response (`201 Created`):**
```json
{
  "message": "Joined room successfully",
  "data": {
    "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
    "user_id": "01a2072f-1111-7aaa-87be-aae26e1d9999",
    "name": "Siti",
    "role": "member"
  }
}

```


* **Error Responses:**
* `409 Conflict` (duplicate join):
```json
{
  "message": "User is already a member of this room"
}

```


* `422 Unprocessable Entity` (room at capacity):
```json
{
  "message": "Room has reached its maximum capacity"
}

```





### 8.4 Get Room Messages

* **Endpoint:** `GET /api/rooms/{room}/messages`
* **Description:** Retrieves persisted messages for the specified room in chronological order (`oldest()`), including sender `id` and `name`.
* **Example Response (`200 OK`):**
```json
{
  "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
  "data": [
    {
      "id": "01a2072f-9999-7bbb-87be-aae26e1d1111",
      "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
      "user_id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
      "body": "Hello everyone!",
      "created_at": "2026-11-23T07:05:00.000000Z",
      "updated_at": "2026-11-23T07:05:00.000000Z",
      "user": {
        "id": "01a2072f-0c92-7a6a-87be-aae26e1d1f39",
        "name": "Budi"
      }
    }
  ]
}

```



### 8.5 Send Message

* **Endpoint:** `POST /api/messages`
* **Description:** Verifies that `user_id` is a member of `room_id`, persists the message in `messages`, and broadcasts `MessageSent` to `chat.room.{room_id}`.
* **Example Request Body:**
```json
{
  "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
  "user_id": "01a2072f-1111-7aaa-87be-aae26e1d9999",
  "body": "Hi Budi, real-time chat is working!"
}

```


* **Example Response (`201 Created`):**
```json
{
  "message": "Message sent and broadcasted",
  "data": {
    "id": "01a2072f-9999-7bbb-87be-aae26e1d2222",
    "room_id": "01a2072f-3316-7353-9c04-4b2444a96051",
    "user_id": "01a2072f-1111-7aaa-87be-aae26e1d9999",
    "body": "Hi Budi, real-time chat is working!",
    "created_at": "2026-11-23T07:06:15.000000Z",
    "updated_at": "2026-11-23T07:06:15.000000Z",
    "user": {
      "id": "01a2072f-1111-7aaa-87be-aae26e1d9999",
      "name": "Siti"
    }
  }
}

```


* **Error Response (`403 Forbidden` — user not in room):**
```json
{
  "message": "User must join the room before sending messages"
}

```
