<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('every application permission is seeded', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Permission::pluck('name')->all())
        ->toEqualCanonicalizing(RolePermissionSeeder::PERMISSIONS);
});

test('the super admin role is seeded without any permissions attached', function () {
    $this->seed(RolePermissionSeeder::class);

    $role = Role::whereName(config('auth.super_admin.role'))->sole();

    expect($role->permissions)->toBeEmpty();
});

test('the project manager role starts with the permissions it declares', function () {
    $this->seed(RolePermissionSeeder::class);

    $role = Role::whereName('Project Manager')->sole();

    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(RolePermissionSeeder::PROJECT_MANAGER_PERMISSIONS);
});

test('re-seeding does not revoke permission changes made to a seeded role', function () {
    $this->seed(RolePermissionSeeder::class);

    $role = Role::whereName('Project Manager')->sole();
    $role->revokePermissionTo('projects.delete');
    $role->givePermissionTo('users.view');

    $this->seed(RolePermissionSeeder::class);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toContain('users.view')
        ->not->toContain('projects.delete');
});

test('every permission granted to the project manager role is an application permission', function () {
    expect(RolePermissionSeeder::PROJECT_MANAGER_PERMISSIONS)
        ->each->toBeIn(RolePermissionSeeder::PERMISSIONS);
});

test('the super admin role and account are protected while others are not', function () {
    $this->seed(RolePermissionSeeder::class);

    $superAdminRole = Role::whereName(config('auth.super_admin.role'))->sole();
    $superAdminUser = User::whereEmail(config('auth.super_admin.email'))->sole();

    expect($superAdminRole->is_protected)->toBeTruthy()
        ->and($superAdminUser->is_protected)->toBeTrue()
        ->and(Role::whereName('Project Manager')->sole()->is_protected)->toBeFalsy()
        ->and(User::factory()->create()->refresh()->is_protected)->toBeFalse();
});

test('the super admin account is seeded with the super admin role', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::whereEmail(config('auth.super_admin.email'))->sole();

    expect($user->hasRole(config('auth.super_admin.role')))->toBeTrue();
});

test('seeding twice does not duplicate permissions, roles, or the super admin account', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Permission::count())->toBe(count(RolePermissionSeeder::PERMISSIONS))
        ->and(Role::whereName(config('auth.super_admin.role'))->count())->toBe(1)
        ->and(Role::whereName('Project Manager')->count())->toBe(1)
        ->and(User::whereEmail(config('auth.super_admin.email'))->count())->toBe(1);
});

test('seeded permissions are resolvable when the calling seeder mutes model events', function () {
    $this->seed(DatabaseSeeder::class);

    expect(app(PermissionRegistrar::class)->getPermissions())
        ->toHaveCount(count(RolePermissionSeeder::PERMISSIONS));
});

test('a super admin passes every gate check without holding the permission', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::whereEmail(config('auth.super_admin.email'))->sole();

    expect($user->can('projects.delete'))->toBeTrue()
        ->and($user->can('a-permission-that-does-not-exist'))->toBeTrue()
        ->and($user->hasPermissionTo('projects.delete'))->toBeFalse();
});

test('a user without the super admin role only has the permissions granted by their roles', function () {
    $this->seed(RolePermissionSeeder::class);

    $role = Role::create(['name' => 'Staff']);
    $role->givePermissionTo('projects.view', 'projects.edit-details');

    $user = User::factory()->create()->assignRole($role);

    expect($user->can('projects.view'))->toBeTrue()
        ->and($user->can('projects.edit-details'))->toBeTrue()
        ->and($user->can('projects.delete'))->toBeFalse()
        ->and($user->can('users.create'))->toBeFalse();
});

test('a user with no roles has no permissions', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();

    expect($user->can('projects.view'))->toBeFalse()
        ->and($user->getAllPermissions())->toBeEmpty();
});
