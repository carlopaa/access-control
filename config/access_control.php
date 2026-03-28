<?php

use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;

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

    'scope' => [
        'model' => null,
        'foreign_key' => 'organization_id',
    ],

    /*
     * Register permission Gate abilities automatically at boot from these enum classes.
     * Example: 'enum_classes' => [\App\Enums\MemberPermission::class, \App\Enums\CustomerPermission::class]
     */
    'permissions' => [
        'enum_classes' => [],
    ],

    'groups' => [],
];
