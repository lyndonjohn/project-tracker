<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a project casts its enum, date, and version attributes', function () {
    $project = Project::factory()->create([
        'status' => ProjectStatus::InProgress,
        'priority' => ProjectPriority::High,
        'start_date' => '2026-01-15',
        'due_date' => '2026-03-01',
        'started_at' => '2026-01-16 09:30:00',
        'completed_at' => null,
    ])->fresh();

    expect($project->status)->toBe(ProjectStatus::InProgress)
        ->and($project->priority)->toBe(ProjectPriority::High)
        ->and($project->start_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($project->start_date->toDateString())->toBe('2026-01-15')
        ->and($project->due_date->toDateString())->toBe('2026-03-01')
        ->and($project->started_at->toDateTimeString())->toBe('2026-01-16 09:30:00')
        ->and($project->completed_at)->toBeNull()
        ->and($project->version)->toBe(1);
});

test('a project belongs to a client that has many projects', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create();

    expect($project->client->is($client))->toBeTrue()
        ->and($client->projects->pluck('id')->all())->toBe([$project->id]);
});

test('a project is soft deleted and records who deleted it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $project->deleted_by = $user->id;
    $project->save();
    $project->delete();

    expect($project->trashed())->toBeTrue()
        ->and(Project::count())->toBe(0)
        ->and(Project::withTrashed()->count())->toBe(1)
        ->and(Project::withTrashed()->sole()->deletedBy->is($user))->toBeTrue();
});

test('a restored project is queryable again', function () {
    $project = Project::factory()->create();
    $project->delete();

    $project->restore();

    expect(Project::count())->toBe(1)
        ->and($project->fresh()->deleted_at)->toBeNull();
});

test('two clients may each own a project with the same name', function () {
    $name = 'Website Redesign';

    Project::factory()->for(Client::factory())->create(['name' => $name]);
    Project::factory()->for(Client::factory())->create(['name' => $name]);

    expect(Project::whereName($name)->count())->toBe(2);
});

test('one client may not own two projects with the same name', function () {
    $client = Client::factory()->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    Project::factory()->for($client)->create(['name' => 'Website Redesign']);
})->throws(UniqueConstraintViolationException::class);

test('a client that still has projects cannot be deleted', function () {
    $project = Project::factory()->create();

    $project->client->delete();
})->throws(QueryException::class);

test('client names are canonicalized to one key regardless of case and whitespace', function () {
    expect(Client::keyFor('Acme Corp'))->toBe('acme corp')
        ->and(Client::keyFor('  ACME   corp '))->toBe('acme corp')
        ->and(Client::keyFor("Acme\tCorp"))->toBe('acme corp');
});

test('clients are unique on their canonical name key', function () {
    Client::factory()->named('Acme Corp')->create();

    Client::factory()->named('  ACME   corp ')->create();
})->throws(UniqueConstraintViolationException::class);
