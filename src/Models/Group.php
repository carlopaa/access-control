<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'name',
        'key',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model'),
            config('access_control.tables.group_user', 'group_user')
        )->withPivot('organization_id')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            config('access_control.tables.group_permission', 'group_permission')
        )->withTimestamps();
    }
}
