<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['channel_id', 'department_id', 'scope_key', 'strategy', 'operator_ids', 'fallback_user_id', 'is_active', 'revision'];

    protected function casts(): array
    {
        return ['operator_ids' => 'array', 'is_active' => 'boolean', 'revision' => 'integer'];
    }
}
