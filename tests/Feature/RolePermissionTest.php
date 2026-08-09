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

    $role = Role::create(['name' => 'Project Manager']);
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
