<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['name'];
}
