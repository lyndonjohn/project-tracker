<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Every permission the application grants.
     *
     * Permissions are only ever assigned to roles, never directly to users.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'projects.view',
        'projects.create',
        'projects.update-status-priority',
        'projects.edit-details',
        'projects.delete',
        'roles.view',
        'roles.create',
        'roles.edit',
        'roles.toggle-status',
        'users.view',
        'users.create',
        'users.assign-role',
        'users.edit',
        'users.update-password',
        'users.toggle-status',
    ];

    /**
     * Seed the permissions, the Super Admin role, and the Super Admin account.
     *
     * The package normally refreshes its permission cache from model events, which
     * a caller using "WithoutModelEvents" would mute, so the cache is flushed here
     * explicitly rather than relying on how this seeder was invoked.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }

        $this->seedSuperAdmin(
            Role::findOrCreate(config('auth.super_admin.role')),
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Seed the Super Admin account configured via the SUPER_ADMIN_* env variables.
     */
    protected function seedSuperAdmin(RoleContract $role): void
    {
        $email = config('auth.super_admin.email');
        $password = config('auth.super_admin.password');

        if (blank($email) || blank($password)) {
            $this->command->warn('SUPER_ADMIN_USER and SUPER_ADMIN_DEFAULT_PASSWORD are not both set, skipping the Super Admin account.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => $password,
            ],
        );

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole($role);
    }
}
