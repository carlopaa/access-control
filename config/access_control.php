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

    /*
     * Request-lifetime memo of resolved permissions, stored in Laravel's hidden
     * Context. Entries are keyed by model class, model key, and scope, and only the
     * authenticated model is memoised. `key` is the prefix of those Context keys.
     */
    'context_cache' => [
        'enabled' => true,
        'key' => 'permissions',
    ],

    /*
     * What a "scope" is belongs to your application: an organization, workspace,
     * team, project, or nothing at all. `foreign_key` is the column on the role and
     * group pivot tables that stores the scope id. `model` is informational and is
     * used by `access-control:install` to derive the default foreign key.
     *
     * Bind Aapolrac\AccessControl\Contracts\ScopeResolver to tell the package which
     * scope is active for checks that are not given an explicit scope. When the
     * resolver returns null, only global (NULL scope) assignments and direct
     * permissions are considered. Applications that do not use scopes do not need
     * to bind a resolver.
     */
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
