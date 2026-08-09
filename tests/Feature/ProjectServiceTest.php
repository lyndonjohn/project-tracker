<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Exceptions\StaleProjectException;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Act as a user whose role grants exactly the given permissions.
 *
 * A dedicated role is built per test rather than reusing a seeded one, because which
 * grants a seeded role holds is administrator-owned data that may legitimately change.
 */
function actingAsUserWith(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

beforeEach(function () {
    $this->service = new ProjectService;

    $this->details = [
        'client_name' => 'Acme Corp',
        'name' => 'Website Redesign',
        'description' => 'A new marketing site.',
        'priority' => ProjectPriority::High,
        'start_date' => '2026-01-15',
        'due_date' => '2026-03-01',
    ];
});

test('creating a project with an unknown client name creates the client', function () {
    actingAsUserWith('projects.create');

    $project = $this->service->create($this->details);

    expect(Client::count())->toBe(1)
        ->and($project->client->name)->toBe('Acme Corp')
        ->and($project->name)->toBe('Website Redesign')
        ->and($project->priority)->toBe(ProjectPriority::High)
        ->and($project->version)->toBe(1);
});

test('creating a project with a known client name reuses that client', function () {
    actingAsUserWith('projects.create');
    $client = Client::factory()->named('Acme Corp')->create();

    $project = $this->service->create($this->details);

    expect(Client::count())->toBe(1)
        ->and($project->client_id)->toBe($client->id);
});

test('a client name typed with different casing and spacing resolves to the existing client', function () {
    actingAsUserWith('projects.create');
    $client = Client::factory()->named('Acme Corp')->create();

    $project = $this->service->create([...$this->details, 'client_name' => '  ACME   corp ']);

    expect(Client::count())->toBe(1)
        ->and($project->client_id)->toBe($client->id)
        ->and($client->fresh()->name)->toBe('Acme Corp');
});

test('a new client name is stored squished, as the first spelling of it', function () {
    actingAsUserWith('projects.create');

    $project = $this->service->create([...$this->details, 'client_name' => '  Acme   Corp ']);

    expect($project->client->name)->toBe('Acme Corp')
        ->and($project->client->name_key)->toBe('acme corp');
});

test('projects saved under differently spelled versions of one client converge on it', function () {
    actingAsUserWith('projects.create');

    $first = $this->service->create([...$this->details, 'client_name' => 'Acme Corp']);
    $second = $this->service->create([...$this->details, 'client_name' => 'acme  CORP', 'name' => 'Intranet']);

    expect(Client::count())->toBe(1)
        ->and($second->client_id)->toBe($first->client_id);
});

test('the clients table is what arbitrates a duplicate name, not application code', function () {
    Client::factory()->named('Acme Corp')->create();

    expect(fn () => DB::table('clients')->insert([
        'name' => 'Acme Corp',
        'name_key' => Client::keyFor('acme  CORP'),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('updating details changes the fields and increments the version', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create(['name' => 'Old Name']);

    $updated = $this->service->updateDetails($project, ['name' => 'New Name'], $project->version);

    expect($updated->name)->toBe('New Name')
        ->and($updated->version)->toBe(2);
});

test('updating details can move a project to another client', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create();

    $updated = $this->service->updateDetails($project, ['client_name' => 'Globex'], $project->version);

    expect($updated->client->name)->toBe('Globex')
        ->and(Client::count())->toBe(2);
});

test('the project returned by an update is fresh enough to be saved again', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create();

    $once = $this->service->updateDetails($project, ['name' => 'First'], $project->version);
    $twice = $this->service->updateDetails($once, ['name' => 'Second'], $once->version);

    expect($twice->name)->toBe('Second')
        ->and($twice->version)->toBe(3);
});

test('updating details with an out of date version is refused and changes nothing', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create(['name' => 'Winning Edit']);
    $this->service->updateDetails($project, ['name' => 'Winning Edit'], 1);

    expect(fn () => $this->service->updateDetails($project, ['name' => 'Losing Edit'], 1))
        ->toThrow(StaleProjectException::class);

    expect($project->fresh()->name)->toBe('Winning Edit');
});

test('a stale write carries the current row so the caller can show it', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create();
    $this->service->updateDetails($project, ['name' => 'Winning Edit'], 1);

    try {
        $this->service->updateDetails($project, ['name' => 'Losing Edit'], 1);
    } catch (StaleProjectException $exception) {
        expect($exception->current->version)->toBe(2)
            ->and($exception->current->name)->toBe('Winning Edit');
    }
});

test('moving a project to in progress stamps when it started', function () {
    actingAsUserWith('projects.update-status');
    $project = Project::factory()->planning()->create();
    $changedAt = CarbonImmutable::parse('2026-02-01 09:30:00');

    $updated = $this->service->updateStatus($project, ProjectStatus::InProgress, $changedAt, $project->version);

    expect($updated->status)->toBe(ProjectStatus::InProgress)
        ->and($updated->started_at->toDateTimeString())->toBe('2026-02-01 09:30:00')
        ->and($updated->completed_at)->toBeNull()
        ->and($updated->version)->toBe(2);
});

test('moving a project to completed stamps when it finished', function () {
    actingAsUserWith('projects.update-status');
    $project = Project::factory()->inProgress()->create();
    $changedAt = CarbonImmutable::parse('2026-03-01 17:00:00');

    $updated = $this->service->updateStatus($project, ProjectStatus::Completed, $changedAt, $project->version);

    expect($updated->status)->toBe(ProjectStatus::Completed)
        ->and($updated->completed_at->toDateTimeString())->toBe('2026-03-01 17:00:00')
        ->and($updated->started_at->toDateTimeString())->toBe($project->started_at->toDateTimeString());
});

test('a status change without a time is stamped now', function () {
    actingAsUserWith('projects.update-status');
    $this->freezeTime();
    $project = Project::factory()->planning()->create();

    $updated = $this->service->updateStatus($project, ProjectStatus::InProgress, null, $project->version);

    expect($updated->started_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('a status that owns no timestamp leaves the stored ones alone', function () {
    actingAsUserWith('projects.update-status');
    $project = Project::factory()->completed()->create();

    $updated = $this->service->updateStatus($project, ProjectStatus::OnHold, null, $project->version);

    expect($updated->status)->toBe(ProjectStatus::OnHold)
        ->and($updated->started_at)->not->toBeNull()
        ->and($updated->completed_at)->not->toBeNull();
});

test('updating status with an out of date version is refused', function () {
    actingAsUserWith('projects.update-status');
    $project = Project::factory()->planning()->create();
    $this->service->updateStatus($project, ProjectStatus::OnHold, null, 1);

    expect(fn () => $this->service->updateStatus($project, ProjectStatus::Completed, null, 1))
        ->toThrow(StaleProjectException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold);
});

test('a write to a project someone else deleted is refused', function () {
    actingAsUserWith('projects.edit-details');
    $project = Project::factory()->create();
    Project::whereKey($project->getKey())->delete();

    expect(fn () => $this->service->updateDetails($project, ['name' => 'Too Late'], $project->version))
        ->toThrow(StaleProjectException::class);
});

test('deleting a project soft deletes it and records who did it', function () {
    $user = actingAsUserWith('projects.delete');
    $project = Project::factory()->create();

    $this->service->delete($project, $user);

    expect(Project::count())->toBe(0)
        ->and(Project::withTrashed()->sole()->deletedBy->is($user))->toBeTrue();
});

test('restoring a project clears who deleted it', function () {
    $user = actingAsUserWith('projects.delete', 'projects.restore');
    $project = Project::factory()->create();
    $this->service->delete($project, $user);

    $restored = $this->service->restore($project);

    expect($restored->trashed())->toBeFalse()
        ->and(Project::count())->toBe(1)
        ->and($project->fresh()->deleted_by)->toBeNull();
});

test('every write is refused for a user without the permission', function (string $action) {
    actingAsUserWith('projects.view');
    $project = Project::factory()->create();

    $call = [
        'create' => fn () => $this->service->create($this->details),
        'updateDetails' => fn () => $this->service->updateDetails($project, ['name' => 'Nope'], $project->version),
        'updateStatus' => fn () => $this->service->updateStatus($project, ProjectStatus::OnHold, null, $project->version),
        'delete' => fn () => $this->service->delete($project, $this->app['auth']->user()),
        'restore' => fn () => $this->service->restore($project),
    ][$action];

    expect($call)->toThrow(AuthorizationException::class);
})->with(['create', 'updateDetails', 'updateStatus', 'delete', 'restore']);

test('a super admin passes every service gate', function () {
    $role = Role::create(['name' => config('auth.super_admin.role'), 'is_protected' => true]);
    $this->actingAs(User::factory()->create()->assignRole($role));

    $project = $this->service->create($this->details);

    expect($project->exists)->toBeTrue();
});
