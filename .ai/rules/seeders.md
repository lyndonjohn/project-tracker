---
paths:
  - 'database/seeders/**'
---

# Seeders

## Don't mute model events around spatie/laravel-permission writes
spatie/laravel-permission refreshes its permission cache from Eloquent model events. A seeder using `WithoutModelEvents` (DatabaseSeeder does) silently leaves a stale/empty cache, so `hasPermissionTo()` then throws `PermissionDoesNotExist` even though the rows exist.

RolePermissionSeeder therefore calls `app(PermissionRegistrar::class)->forgetCachedPermissions()` at the end of `run()` instead of relying on events. Do the same in any seeder that creates roles or permissions. Covered by the "seeded permissions are resolvable when the calling seeder mutes model events" test.

## Flush the permission cache before syncing permissions to roles
`syncPermissions()`/`givePermissionTo()` resolve names through the registrar's cache. When the calling seeder mutes model events, permissions created earlier in the same run are not in that cache, so syncing throws PermissionDoesNotExist. RolePermissionSeeder therefore calls `forgetCachedPermissions()` right after creating permissions (before role syncing) and again at the end of `run()`.

## Seeded role permissions are first-run defaults, never re-synced
Role→permission mappings are administrator-owned data. Changing a seeded role's permissions at runtime is valid, so seeders apply defaults only when they create the role (`if ($role->wasRecentlyCreated)`) and must never `syncPermissions()` an existing one — re-running `db:seed` on a live database would silently revoke admins' changes. Covered by "re-seeding does not revoke permission changes made to a seeded role" in RolePermissionTest. Corollary for tests: never assert a literal list of a role's grants (that data legitimately changes, so the test only ever gets edited to match); reference the seeder constant and assert seeding behavior instead.
