<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /** @var list<string> */
    public const array CLIENTS = [
        'Acme Corporation',
        'Globex',
        'Initech',
    ];

    /**
     * Projects are only created alongside a client this run created, so re-running never
     * adds a second set to a client that already exists.
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
