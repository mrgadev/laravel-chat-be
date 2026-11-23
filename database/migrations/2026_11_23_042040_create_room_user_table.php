<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('room_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->onDelete('cascade');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('role')->default('member'); // role of the user in the room (e.g., member, admin
            $table->timestamp('last_read_at')->nullable(); // to track when the user last read messages in the room
            $table->timestamps();

            $table->unique(['room_id', 'user_id']); // Ensure a user can only be in a room once
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_user');
    }
};
