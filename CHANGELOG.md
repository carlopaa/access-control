# Changelog

All notable changes to `carlopaa/access-control` will be documented in this file.

## v0.2.0 — Scope-Aware Authorization - 2026-10-01

### Scope-Aware Authorization

This release introduces generalized scope-aware authorization for Laravel applications.

#### Added

- Scope-aware roles, groups, and permissions
- Global and scoped role assignments
- Global and scoped group assignments
- Explicit global role/group APIs
- Scope-aware permission and role checks
- Scope-isolated permission caching
- Scope-aware authorization middleware
- Laravel Gate integration that preserves policy and ability handling
- Support for non-scoped Laravel applications without a ScopeResolver
- Migration support for existing v0.1.x installations
- Expanded authorization and regression test coverage

#### Compatibility

- Laravel 11, 12, and 13
- PHP 8.3+
- Filament is not required by the core package
- Scope resolution is optional

#### Maintenance

- Updated Orchestra Testbench dependency
- Updated GitHub Actions checkout dependency

## Unreleased (proposed v0.2.0)

### Changed (behaviour)

- Authorization is now scope-aware at the query level. `getAllPermissions()`, `hasPermission()`, `hasAnyPermission()`, `hasRole()` and `hasAnyRole()` evaluate the current scope (from `ScopeResolver`) plus global grants, instead of every scope. A grant in scope A no longer satisfies a check in scope B, and a missing scope only sees global grants.
- `withRoleInScope()` / `withAnyRolesInScope()` without a resolvable scope match only global (`NULL` scope) rows instead of falling back to a cross-scope query.
- The `Gate::before` hook now receives ability arguments and abstains whenever a policy or a defined ability can handle the call, so `$user->can('update', $post)` reaches the policy with `$post` intact.
- Context cache entries are keyed by model class, model key, and scope (`permissions:<class>:<key>` holding one list per scope) instead of a single shared `permissions` key.
- The `role_user` scope column is nullable: `NULL` is a global role assignment, an id is a scoped one. Fresh installs get this from `create_role_user_table`; existing installs run the new `make_role_user_scope_nullable` migration, which only drops the `NOT NULL` constraint.

### Fixed

- Cross-user leak: after switching the authenticated user within one process, the previous user's memoised permissions were returned for the new user.
- Scope-move corruption: `assignGroup()` / `assignRole()` for a group or role already held in another scope updated that row's scope instead of adding a new row. `syncGroups()` / `syncRoles()` also detached rows from other scopes.
- Stale cache: group assignment helpers and `RoleGroupSync` now flush the permission memo.

### Added

- Optional `$scope` argument on `getAllPermissions()`, `hasPermission()`, `hasAnyPermission()`, `hasRole()`, `hasAnyRole()`.
- `hasRoleInAnyScope()`, `hasAnyRoleInAnyScope()` as explicit cross-scope queries.
- Explicit global group helpers: `assignGlobalGroup()`, `assignGlobalGroups()`, `syncGlobalGroups()`, `revokeGlobalGroup()`, `revokeGlobalGroups()`.
- Explicit global role helpers: `assignGlobalRole()`, `assignGlobalRoles()`, `syncGlobalRoles()`, `revokeGlobalRole()`, `revokeGlobalRoles()`.
- `RoleGroupSync::attachGlobal()` and `RoleGroupSync::syncGlobalDefaultsForRoles()` for role → group defaults of global roles.
- `flushPermissionCache()` for changes made outside the package helpers.
- `Support\GateAbilityResolver` encapsulating the Gate hook rules.
- Regression suites for scoped isolation, global/no-scope semantics, cache isolation and invalidation, Gate argument preservation, middleware consistency, and scope-preserving assignment/sync.

### Deprecated

- `hasRoleInOrg()`, `hasAnyRoleInOrg()`, `withRoleInOrg()`, `withAnyRolesInOrg()` in favour of the `InScope` variants (still functional).

## v0.1.0 — 2026-03-26

### Added

- `HasAccessControl` trait providing role, group, and permission resolution for Eloquent models
- Deny-aware permission resolution: a `:deny` permission token overrides any allow from direct or group sources
- Direct user permission assignment APIs: `assignPermission`, `assignPermissions`, `revokePermission`, `syncDirectPermissions`, `clearDirectPermissions`, `getDirectPermissions`
- `TenantResolver` contract and `DefaultTenantResolver` no-op implementation for custom multi-tenant context
- `GroupSync` service for config-driven, org-scoped role → group defaults with idempotent attach/detach
- `GateRegistrar` with Gate before-hook and automatic enum class permission registration
- `access.permission` and `access.role` middleware aliases for route-level access control
- `access-control:sync` Artisan command to upsert permissions into the database from configured enum classes
- Publishable `config/access_control.php` covering models, tables, cache, enum classes, and group defaults
- Six publishable database migration stubs for roles, groups, permissions, and pivot tables
