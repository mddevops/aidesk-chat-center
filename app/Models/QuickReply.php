<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['title', 'body'];
}
