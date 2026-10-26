<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ConversationActivity extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['conversation_id', 'actor_id', 'type', 'changes', 'reason', 'version'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'version' => 'integer'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
