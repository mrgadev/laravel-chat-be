<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Room extends Model
{
    use HasUuids;
    protected $fillable = ['created_by', 'name', 'description'];

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users() {
        return $this->belongsToMany(User::class)
        ->using(RoomUser::class)
        ->withPivot(['role', 'last_read_at'])
        ->withTimestamps();
    }

    public function messages() {
        return $this->hasMany(Message::class);
    }
}
