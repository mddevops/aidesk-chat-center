<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['visitor_session_id', 'operator_id', 'message_id', 'name', 'mime', 'size', 'path', 'sha256'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
