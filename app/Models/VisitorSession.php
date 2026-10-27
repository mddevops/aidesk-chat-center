<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VisitorSession extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['department_id', 'channel_id', 'contact_id', 'token_hash', 'expires_at', 'consent_version', 'consented_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'consented_at' => 'datetime', 'last_read_sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }
}
