---
paths:
  - 'app/**'
---

# App

## Authorization: permissions on roles only, Super Admin bypasses gates
Permissions are assigned to roles only, never directly to users. Add new permissions to `RolePermissionSeeder::PERMISSIONS` using dot-notation slugs (`projects.view`, `users.assign-role`).

The "Super Admin" role holds no permission rows. It passes every check via the `Gate::before` callback in `AppServiceProvider::configureDefaults()`, so `can()` returns true for it while `hasPermissionTo()` returns false — check with `can()`/`@can`, never `hasPermissionTo()`. The role name and the seeded account come from `config('auth.super_admin.*')`.

No policies are used for this; gate abilities are the permission names.
