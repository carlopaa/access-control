<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'key',
    ];

    public function getTable(): string
    {
        return (string) config('access_control.tables.roles', 'roles');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model'),
            config('access_control.tables.role_user', 'role_user'),
            'role_id',
            'user_id'
        )->withPivot('organization_id')->withTimestamps();
    }
}
