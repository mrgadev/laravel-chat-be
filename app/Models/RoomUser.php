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
