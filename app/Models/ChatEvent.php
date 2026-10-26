<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ChatEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['event_id', 'event_type', 'conversation_id', 'message_id', 'available_at'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'published_at' => 'datetime', 'attempts' => 'integer'];
    }
}
