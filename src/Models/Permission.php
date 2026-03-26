<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'description',
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            Group::class,
            config('access_control.tables.group_permission', 'group_permission')
        )->withTimestamps();
    }
}
