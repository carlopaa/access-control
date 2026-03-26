<?php

use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;

return [
    'models' => [
        'role' => Role::class,
        'group' => Group::class,
        'permission' => Permission::class,
    ],

    'tables' => [
        'roles' => 'roles',
        'groups' => 'groups',
        'permissions' => 'permissions',
        'role_user' => 'role_user',
        'group_user' => 'group_user',
        'group_permission' => 'group_permission',
    ],

    'context_cache' => [
        'enabled' => true,
        'key' => 'permissions',
    ],

    /*
     * Register permission Gate abilities automatically at boot from these enum classes.
     * Example: 'enum_classes' => [MemberPermission::class, CustomerPermission::class]
     */
    'permissions' => [
        'enum_classes' => [],
    ],

    'groups' => [],
];
