<?php

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

test('the command issues a token that authenticates a request', function () {
    $user = User::factory()->create(['email' => 'pm@example.test']);

    $this->artisan('api:token', ['email' => 'pm@example.test'])->assertSuccessful();

    $token = $user->tokens()->sole();

    expect($token->name)->toBe('api');

    // The plain text is only ever printed, never stored, so the test authenticates with the
    // string the command wrote rather than reading one out of the database.
    Artisan::call('api:token', ['email' => 'pm@example.test', '--name' => 'postman']);

    $printed = collect(explode("\n", Artisan::output()))
        ->map(fn (string $line) => trim($line))
        ->first(fn (string $line) => preg_match('/^\d+\|\w+$/', $line) === 1);

    expect($printed)->not->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$printed)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('the command fails on an unknown email without issuing anything', function () {
    $this->artisan('api:token', ['email' => 'nobody@example.test'])
        ->expectsOutputToContain('No user with the email [nobody@example.test].')
        ->assertFailed();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('the command names the token so it can be revoked on its own', function () {
    User::factory()->create(['email' => 'pm@example.test']);

    $this->artisan('api:token', ['email' => 'pm@example.test', '--name' => 'postman'])->assertSuccessful();

    expect(User::whereEmail('pm@example.test')->sole()->tokens()->sole()->name)->toBe('postman');
});

test('the demo user is seeded with the project manager role and can use the api', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoUserSeeder::class);

    $user = User::whereEmail(DemoUserSeeder::EMAIL)->sole();

    expect($user->hasRole(DemoUserSeeder::ROLE))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->getJson('/api/v1/projects')->assertOk();
});

test('the demo user is not a super admin, so a permission it lacks is still refused', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoUserSeeder::class);

    $user = User::whereEmail(DemoUserSeeder::EMAIL)->sole();
    $user->roles->first()->revokePermissionTo('projects.create');

    $this->actingAs($user)
        ->postJson('/api/v1/projects', [
            'client_name' => 'Acme Corp',
            'name' => 'Intranet',
            'start_date' => '2026-09-01',
        ])
        ->assertForbidden();
});

test('re-seeding the demo user does not create a second account', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class);

    expect(User::whereEmail(DemoUserSeeder::EMAIL)->count())->toBe(1);
});
