<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['name', 'origin', 'showroom_id'];
}
