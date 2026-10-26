<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use BelongsToOrganization;

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    protected $fillable = ['channel_id', 'visitor_session_id', 'contact_id'];

    protected $attributes = ['status' => 'pending', 'last_sequence' => 0, 'last_public_sequence' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['last_sequence' => 'integer', 'last_public_sequence' => 'integer', 'version' => 'integer', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }
}
