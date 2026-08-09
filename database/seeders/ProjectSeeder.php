<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * The demo clients this seeder creates on a first run.
     *
     * @var list<string>
     */
    public const array CLIENTS = [
        'Acme Corporation',
        'Globex',
        'Initech',
    ];

    /**
     * Seed a handful of clients, each with a project in a different status.
     *
     * Projects are only created alongside a client this run created, so re-running the
     * seeder never adds a second set to a client that already exists.
     */
    public function run(): void
    {
        foreach (self::CLIENTS as $name) {
            $client = Client::firstOrCreate(
                ['name_key' => Client::keyFor($name)],
                ['name' => $name],
            );

            if (! $client->wasRecentlyCreated) {
                continue;
            }

            Project::factory()->for($client)->planning()->create();
            Project::factory()->for($client)->inProgress()->create();
            Project::factory()->for($client)->completed()->create();
        }
    }
}
