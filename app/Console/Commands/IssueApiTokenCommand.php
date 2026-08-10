<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Tokens are minted on demand rather than seeded, so no working credential is ever written
 * into the repository or into every seeded database. Sanctum only stores a hash, so the
 * plain text printed here is the one chance to copy it.
 */
class IssueApiTokenCommand extends Command
{
    protected $signature = 'api:token {email : The user to issue a token for} {--name=api : A label, so tokens can be revoked individually}';

    protected $description = 'Issue a Sanctum API token for a user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $this->components->error("No user with the email [{$email}].");

            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'))->plainTextToken;
        $roles = $user->getRoleNames();

        $this->components->info(sprintf(
            'Token for %s (%s):',
            $user->email,
            $roles->isEmpty() ? 'no role' : $roles->implode(', '),
        ));

        $this->line($token);
        $this->newLine();
        $this->components->bulletList(["Authorization: Bearer {$token}"]);

        // A token carries every permission its owner has: abilities are not scoped yet.
        if ($user->hasRole(config('auth.super_admin.role'))) {
            $this->components->warn('This user is a Super Admin, so the token passes every permission check.');
        }

        return self::SUCCESS;
    }
}
