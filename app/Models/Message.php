<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['conversation_id', 'sequence', 'sender_key', 'sender_type', 'operator_id', 'kind', 'client_message_id', 'payload_hash', 'body'];

    protected $hidden = ['payload_hash', 'sender_key'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
