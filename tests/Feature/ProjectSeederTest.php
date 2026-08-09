<?php

use App\Models\Client;
use App\Models\Project;
use Database\Seeders\ProjectSeeder;

test('the seeder creates every declared client with projects', function () {
    $this->seed(ProjectSeeder::class);

    expect(Client::pluck('name')->all())->toEqualCanonicalizing(ProjectSeeder::CLIENTS)
        ->and(Client::has('projects')->count())->toBe(count(ProjectSeeder::CLIENTS));
});

test('re-seeding does not duplicate clients or their projects', function () {
    $this->seed(ProjectSeeder::class);

    $projectCount = Project::count();

    $this->seed(ProjectSeeder::class);

    expect(Client::count())->toBe(count(ProjectSeeder::CLIENTS))
        ->and(Project::count())->toBe($projectCount);
});
