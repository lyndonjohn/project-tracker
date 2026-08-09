---
paths:
  - 'database/seeders/**'
---

# Seeders

## Don't mute model events around spatie/laravel-permission writes
spatie/laravel-permission refreshes its permission cache from Eloquent model events. A seeder using `WithoutModelEvents` (DatabaseSeeder does) silently leaves a stale/empty cache, so `hasPermissionTo()` then throws `PermissionDoesNotExist` even though the rows exist.

RolePermissionSeeder therefore calls `app(PermissionRegistrar::class)->forgetCachedPermissions()` at the end of `run()` instead of relying on events. Do the same in any seeder that creates roles or permissions. Covered by the "seeded permissions are resolvable when the calling seeder mutes model events" test.
