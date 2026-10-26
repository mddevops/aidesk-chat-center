<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ConversationRead extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['conversation_id', 'user_id', 'last_read_sequence'];

    protected function casts(): array
    {
        return ['last_read_sequence' => 'integer'];
    }
}
