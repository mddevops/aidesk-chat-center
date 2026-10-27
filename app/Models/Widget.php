<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['site_id', 'channel_id', 'draft', 'revision', 'published_version'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'published_version' => 'integer'];
    }
}
