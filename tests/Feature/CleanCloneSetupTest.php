<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dotenv\Dotenv;

/**
 * The values a clean clone starts with, straight out of .env.example.
 *
 * @return array<string, string|null>
 */
function envExample(): array
{
    return Dotenv::parse(file_get_contents(base_path('.env.example')));
}

test('composer setup seeds the database', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['scripts']['setup'])->toContain('@php artisan db:seed --force');
});

test('the credentials in .env.example seed an account that can reach the projects pages', function () {
    $env = envExample();

    config([
        'auth.super_admin.email' => $env['SUPER_ADMIN_USER'] ?? null,
        'auth.super_admin.password' => $env['SUPER_ADMIN_DEFAULT_PASSWORD'] ?? null,
    ]);

    $this->seed(RolePermissionSeeder::class);

    $superAdmin = User::whereEmail($env['SUPER_ADMIN_USER'])->sole();

    $this->actingAs($superAdmin)
        ->get(route('projects.index'))
        ->assertOk();
});
