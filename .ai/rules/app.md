---
paths:
  - 'app/**'
---

# App

## Authorization: permissions on roles only, Super Admin bypasses gates
Permissions are assigned to roles only, never directly to users. Add new permissions to `RolePermissionSeeder::PERMISSIONS` using dot-notation slugs (`projects.view`, `users.assign-role`).

The "Super Admin" role holds no permission rows. It passes every check via the `Gate::before` callback in `AppServiceProvider::configureDefaults()`, so `can()` returns true for it while `hasPermissionTo()` returns false — check with `can()`/`@can`, never `hasPermissionTo()`. The role name and the seeded account come from `config('auth.super_admin.*')`.

No policies are used for this; gate abilities are the permission names.

## Protected roles and users can never be deleted (is_protected)
`users.is_protected` and `roles.is_protected` (boolean, default false) mark records that must never be deleted — there is no bypass, not even for Super Admin. The seeder sets the flag on the Super Admin role and account. Any role/user delete feature must refuse when `is_protected` is true, in the business-logic layer (not just hidden buttons). Covered by the "the super admin role and account are protected" test in RolePermissionTest.

## Validation rules live in one class per domain, shared by Livewire and API
Livewire form objects and API Form Requests must not define rules themselves — both delegate to a static definition class in `app/Validation/` (e.g. `App\Validation\ProjectRules::details()/status()/listing()`, plus `messages()` and `attributes()`). Keep these out of `app/Rules/`, which is reserved for `ValidationRule` objects from `make:rule`. Form objects extend Livewire\Form and Form Requests extend FormRequest, so neither can inherit from the other; a stateless shared class is the only way to define each rule once. Sort/order columns must come from a constant whitelist on that class (`SORTABLE`) validated with `Rule::in()` — `orderBy()` interpolates identifiers rather than binding them.

## Authorization is enforced server-side; @can is presentational only
Every write action authorizes on the server — `Gate::authorize('<permission>')` at the top of the form object / service entry point, plus `can:` middleware on the route. `@can` in Blade only hides controls the user cannot use; it is never the enforcement, because a Livewire action can be invoked from the browser whether or not its button rendered. Tests must assert the 403 by calling the action directly, not by checking the button is absent.
