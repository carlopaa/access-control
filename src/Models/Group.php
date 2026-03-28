<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    protected $fillable = [
        'name',
        'key',
    ];

    public function getTable(): string
    {
        return (string) config('access_control.tables.groups', 'groups');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model'),
            config('access_control.tables.group_user', 'group_user'),
            'group_id',
            'user_id'
        )->withPivot($this->scopeForeignKey())->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('access_control.models.permission'),
            config('access_control.tables.group_permission', 'group_permission'),
            'group_id',
            'permission_id'
        )->withTimestamps();
    }

    protected function scopeForeignKey(): string
    {
        return (string) config('access_control.scope.foreign_key', 'organization_id');
    }
}
