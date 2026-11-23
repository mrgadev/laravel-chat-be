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
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->onDelete('cascade');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->text('content');
            $table->timestamps();

            $table->index(['user_id', 'created_at']); // Index for faster retrieval of messages by user and timestamp
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
