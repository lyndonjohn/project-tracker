<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A Project Manager account for trying the app and the API.
 *
 * The Super Admin passes every gate through the Gate::before bypass, so a session or token
 * for it can never produce a 403 — permission checks look inert when exercised as that
 * account. This user holds the Project Manager role like a real one would.
 *
 * Called only outside production (see DatabaseSeeder), and after RolePermissionSeeder,
 * which is what creates the role assigned here.
 */
class DemoUserSeeder extends Seeder
{
    public const string EMAIL = 'pm@example.com';

    public const string PASSWORD = 'password';

    public const string ROLE = 'Project Manager';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Pat Morgan',
                'password' => self::PASSWORD,
            ],
        );

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole(self::ROLE);
    }
}
