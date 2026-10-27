<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OperatorState extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['revision', 'user_id', 'status', 'heartbeat_at', 'capacity', 'schedule'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'heartbeat_at' => 'datetime', 'capacity' => 'integer', 'schedule' => 'array'];
    }
}
