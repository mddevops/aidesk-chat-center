<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class WidgetVersion extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['widget_id', 'published_by', 'version', 'configuration'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published widget versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Published widget versions are immutable.'));
    }
}
