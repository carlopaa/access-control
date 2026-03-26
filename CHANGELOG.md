# Changelog

All notable changes to `carlopaa/access-control` will be documented in this file.

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
