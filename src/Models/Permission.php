<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function getTable(): string
    {
        return (string) config('access_control.tables.permissions', 'permissions');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('access_control.models.group'),
            config('access_control.tables.group_permission', 'group_permission'),
            'permission_id',
            'group_id'
        )->withTimestamps();
    }
}
