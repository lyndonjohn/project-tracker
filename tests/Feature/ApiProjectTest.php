<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Livewire\Forms\ProjectForm;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Act as a user whose role grants exactly the given permissions.
 */
function apiUserWith(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

/**
 * A planning project for "Acme Corp", reusing that client the way the service would rather
 * than creating a second one and colliding with the unique name key.
 */
function apiProject(array $attributes = []): Project
{
    $client = Client::firstOrCreate(
        ['name_key' => Client::keyFor('Acme Corp')],
        ['name' => 'Acme Corp'],
    );

    return Project::factory()->for($client)->planning()->create($attributes);
}

/**
 * Every write endpoint, with the permission that guards it. "{id}" is filled in per test.
 */
dataset('endpoints', [
    'list' => ['get', '/api/v1/projects', 'projects.view'],
    'show' => ['get', '/api/v1/projects/{id}', 'projects.view'],
    'create' => ['post', '/api/v1/projects', 'projects.create'],
    'update' => ['put', '/api/v1/projects/{id}', 'projects.edit-details'],
    'status' => ['patch', '/api/v1/projects/{id}/status', 'projects.update-status'],
    'delete' => ['delete', '/api/v1/projects/{id}', 'projects.delete'],
    'restore' => ['post', '/api/v1/projects/{id}/restore', 'projects.restore'],
]);

test('a guest is refused with a json 401', function (string $method, string $uri) {
    $project = apiProject();

    $this->json($method, str_replace('{id}', (string) $project->id, $uri))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
})->with('endpoints');

test('a user without the permission is refused with a 403', function (string $method, string $uri, string $permission) {
    $project = apiProject();

    // Every project permission except the one this endpoint needs, so the refusal can only
    // be that permission and not merely an unauthorized user.
    apiUserWith(...array_diff([
        'projects.view', 'projects.create', 'projects.edit-details',
        'projects.update-status', 'projects.delete', 'projects.restore',
    ], [$permission]));

    $this->json($method, str_replace('{id}', (string) $project->id, $uri))
        ->assertForbidden();
})->with('endpoints');

test('a sanctum token can reach the api', function () {
    $user = apiUserWith('projects.view');
    apiProject();

    $this->withHeader('Authorization', 'Bearer '.$user->createToken('integration')->plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('the list returns projects with their client', function () {
    apiUserWith('projects.view');
    $project = apiProject(['name' => 'Website Redesign']);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Website Redesign')
        ->assertJsonPath('data.0.client.name', 'Acme Corp')
        ->assertJsonPath('data.0.status.value', ProjectStatus::Planning->value)
        ->assertJsonPath('data.0.status.label', ProjectStatus::Planning->label())
        ->assertJsonPath('data.0.version', $project->version)
        ->assertJsonPath('meta.total', 1);
});

test('the list excludes deleted projects until they are asked for', function () {
    apiUserWith('projects.view');
    apiProject(['name' => 'Live']);
    Project::factory()->for(Client::factory())->deletedBy()->create(['name' => 'Trashed']);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Live');

    $response = $this->getJson('/api/v1/projects?with_deleted=1')->assertOk();

    expect($response->json('data.*.name'))->toEqualCanonicalizing(['Live', 'Trashed']);
});

test('a deleted project carries its deletion fields and a live one does not', function () {
    apiUserWith('projects.view');
    $deleter = User::factory()->create();
    Project::factory()->for(Client::factory())->deletedBy($deleter)->create();

    $this->getJson('/api/v1/projects?with_deleted=1')
        ->assertOk()
        ->assertJsonPath('data.0.deleted_by', $deleter->id)
        ->assertJsonStructure(['data' => [['deleted_at']]]);

    apiProject();

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonMissingPath('data.0.deleted_at');
});

test('the list searches, filters and sorts', function () {
    apiUserWith('projects.view');
    apiProject(['name' => 'Alpha', 'priority' => ProjectPriority::Low]);
    apiProject(['name' => 'Beta', 'priority' => ProjectPriority::High]);
    Project::factory()->for(Client::factory()->named('Globex'))->inProgress()->create([
        'name' => 'Gamma',
        // Pinned: the factory randomizes priority, which would sometimes make this a
        // second match for the high-priority filter below.
        'priority' => ProjectPriority::Low,
    ]);

    expect($this->getJson('/api/v1/projects?search=Globex')->json('data.*.name'))->toBe(['Gamma']);
    expect($this->getJson('/api/v1/projects?priority=high')->json('data.*.name'))->toBe(['Beta']);
    expect($this->getJson('/api/v1/projects?status=in_progress')->json('data.*.name'))->toBe(['Gamma']);
    expect($this->getJson('/api/v1/projects?sort=name&direction=asc')->json('data.*.name'))->toBe(['Alpha', 'Beta', 'Gamma']);
    expect($this->getJson('/api/v1/projects?sort=name&direction=desc')->json('data.*.name'))->toBe(['Gamma', 'Beta', 'Alpha']);
});

test('an unlisted sort column is rejected rather than reaching the query', function (string $sort) {
    apiUserWith('projects.view');

    $this->getJson('/api/v1/projects?sort='.urlencode($sort))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
})->with([
    'a real column that is not sortable' => 'password',
    'an injection attempt' => 'name); drop table projects;--',
]);

test('an unlisted sort direction is rejected', function () {
    apiUserWith('projects.view');

    $this->getJson('/api/v1/projects?direction=sideways')
        ->assertStatus(422)
        ->assertJsonValidationErrors('direction');
});

test('a page size above the cap is rejected', function () {
    apiUserWith('projects.view');

    $this->getJson('/api/v1/projects?per_page=101')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');

    $this->getJson('/api/v1/projects?per_page=100')->assertOk();
});

test('the page size is honoured', function () {
    apiUserWith('projects.view');
    apiProject();
    apiProject();

    $this->getJson('/api/v1/projects?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2);
});

test('a single project can be read', function () {
    apiUserWith('projects.view');
    $project = apiProject(['name' => 'Website Redesign']);

    $this->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.name', 'Website Redesign')
        ->assertJsonPath('data.client.name', 'Acme Corp');
});

test('an unknown project is a json 404 even without an accept header', function () {
    apiUserWith('projects.view');

    $this->get('/api/v1/projects/9999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

test('a project can be created, and its client with it', function () {
    apiUserWith('projects.create');

    $this->postJson('/api/v1/projects', [
        'client_name' => 'Initech',
        'name' => 'Intranet',
        'description' => 'Internal tools.',
        'priority' => ProjectPriority::Medium->value,
        'status' => ProjectStatus::Planning->value,
        'start_date' => '2026-01-15',
        'due_date' => '2026-03-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Intranet')
        ->assertJsonPath('data.client.name', 'Initech')
        ->assertJsonPath('data.priority.value', ProjectPriority::Medium->value)
        ->assertJsonPath('data.version', 1);

    expect(Client::where('name', 'Initech')->exists())->toBeTrue();
});

test('creating validates the same rules the ui does', function (array $payload, string $field) {
    apiUserWith('projects.create');

    $this->postJson('/api/v1/projects', [...[
        'client_name' => 'Acme Corp',
        'name' => 'Intranet',
        'start_date' => '2026-01-15',
    ], ...$payload])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'a missing name' => [['name' => ''], 'name'],
    'a due date before the start date' => [['due_date' => '2026-01-01'], 'due_date'],
    'an unknown status' => [['status' => 'abandoned'], 'status'],
    'an unknown priority' => [['priority' => 'urgent'], 'priority'],
    'a missing client name' => [['client_name' => ''], 'client_name'],
]);

test('a client name sent as an array is a 422 rather than a type error', function () {
    apiUserWith('projects.create');

    $this->postJson('/api/v1/projects', [
        'client_name' => ['Acme Corp'],
        'name' => 'Intranet',
        'start_date' => '2026-01-15',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('client_name');
});

test('a project name must be unique for its client but not across clients', function () {
    apiUserWith('projects.create');
    $existing = apiProject(['name' => 'Website Redesign']);

    $this->postJson('/api/v1/projects', [
        'client_name' => $existing->client->name,
        'name' => 'Website Redesign',
        'start_date' => '2026-01-15',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/projects', [
        'client_name' => 'Globex',
        'name' => 'Website Redesign',
        'start_date' => '2026-01-15',
    ])->assertCreated();
});

test('a project can be updated', function () {
    apiUserWith('projects.edit-details');
    $project = apiProject(['name' => 'Website Redesign']);

    $this->putJson("/api/v1/projects/{$project->id}", [
        'client_name' => 'Globex',
        'name' => 'Website Rebuild',
        'priority' => ProjectPriority::High->value,
        'start_date' => '2026-02-01',
        'version' => $project->version,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Website Rebuild')
        ->assertJsonPath('data.client.name', 'Globex')
        ->assertJsonPath('data.version', $project->version + 1);
});

test('updating does not accept a status', function () {
    apiUserWith('projects.edit-details');
    $project = apiProject(['name' => 'Website Redesign']);

    $this->putJson("/api/v1/projects/{$project->id}", [
        'client_name' => $project->client->name,
        'name' => 'Website Redesign',
        'start_date' => '2026-02-01',
        'status' => ProjectStatus::Completed->value,
        'version' => $project->version,
    ])->assertOk();

    expect($project->fresh()->status)->toBe(ProjectStatus::Planning);
});

test('updating without a version is rejected', function () {
    apiUserWith('projects.edit-details');
    $project = apiProject();

    $this->putJson("/api/v1/projects/{$project->id}", [
        'client_name' => $project->client->name,
        'name' => $project->name,
        'start_date' => '2026-02-01',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('version');
});

test('a stale update is a 409 that leaves the winning edit intact', function () {
    apiUserWith('projects.edit-details');
    $project = apiProject(['name' => 'Website Redesign']);
    $staleVersion = $project->version;

    $this->putJson("/api/v1/projects/{$project->id}", [
        'client_name' => $project->client->name,
        'name' => 'Saved First',
        'start_date' => '2026-02-01',
        'version' => $staleVersion,
    ])->assertOk();

    $this->putJson("/api/v1/projects/{$project->id}", [
        'client_name' => $project->client->name,
        'name' => 'Saved Second',
        'start_date' => '2026-02-01',
        'version' => $staleVersion,
    ])
        ->assertStatus(409)
        ->assertJsonPath('version', $staleVersion + 1);

    expect($project->fresh()->name)->toBe('Saved First');
});

test('a status change stamps the timestamp it is given', function () {
    apiUserWith('projects.update-status');
    $project = apiProject();
    $startedAt = CarbonImmutable::parse('2026-02-03 09:30:00');

    $this->patchJson("/api/v1/projects/{$project->id}/status", [
        'status' => ProjectStatus::InProgress->value,
        'changed_at' => $startedAt->toDateTimeString(),
        'version' => $project->version,
    ])
        ->assertOk()
        ->assertJsonPath('data.status.value', ProjectStatus::InProgress->value)
        ->assertJsonPath('data.version', $project->version + 1);

    expect($project->fresh()->started_at->eq($startedAt))->toBeTrue();
});

test('a status change cannot complete a project before it started', function () {
    apiUserWith('projects.update-status');
    $project = Project::factory()->for(Client::factory())->inProgress()->create();

    $this->patchJson("/api/v1/projects/{$project->id}/status", [
        'status' => ProjectStatus::Completed->value,
        'changed_at' => $project->started_at->subDay()->toDateTimeString(),
        'version' => $project->version,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('changed_at');
});

test('a status change cannot start a project after it was completed', function () {
    apiUserWith('projects.update-status');
    $project = Project::factory()->for(Client::factory())->completed()->create();

    $this->patchJson("/api/v1/projects/{$project->id}/status", [
        'status' => ProjectStatus::InProgress->value,
        'changed_at' => $project->completed_at->addDay()->toDateTimeString(),
        'version' => $project->version,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('changed_at');
});

test('a stale status change is a 409', function () {
    apiUserWith('projects.update-status');
    $project = apiProject();

    $this->patchJson("/api/v1/projects/{$project->id}/status", [
        'status' => ProjectStatus::InProgress->value,
        'changed_at' => CarbonImmutable::now()->toDateTimeString(),
        'version' => $project->version + 5,
    ])
        ->assertStatus(409)
        ->assertJsonPath('version', $project->version);
});

test('a project can be deleted and restored', function () {
    $user = apiUserWith('projects.delete', 'projects.restore', 'projects.view');
    $project = apiProject();

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

    $deleted = Project::withTrashed()->find($project->id);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($user->id);

    $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();

    $this->postJson("/api/v1/projects/{$project->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id);

    $restored = $project->fresh();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull();
});

test('a due date range applies only when both bounds are given', function () {
    apiUserWith('projects.view');
    apiProject(['name' => 'Due Soon', 'due_date' => '2026-03-01']);
    apiProject(['name' => 'Due Late', 'due_date' => '2027-03-01']);

    expect($this->getJson('/api/v1/projects?due_from=2026-01-01&due_to=2026-12-31')->json('data.*.name'))
        ->toBe(['Due Soon']);

    // One bound alone is not half a range — it is no range, as documented in docs/api.md.
    expect($this->getJson('/api/v1/projects?due_from=2026-01-01')->json('data.*.name'))
        ->toEqualCanonicalizing(['Due Soon', 'Due Late']);

    expect($this->getJson('/api/v1/projects?due_to=2026-12-31')->json('data.*.name'))
        ->toEqualCanonicalizing(['Due Soon', 'Due Late']);
});

test('restoring a project that is not deleted succeeds and changes nothing', function () {
    apiUserWith('projects.restore');
    $project = apiProject(['name' => 'Still Here']);

    $this->postJson("/api/v1/projects/{$project->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.name', 'Still Here')
        ->assertJsonMissingPath('data.deleted_at');

    expect($project->fresh()->trashed())->toBeFalse();
});

test('the api and the ui create a project under the same rules', function () {
    Client::factory()->named('Acme Corp')->create();

    $form = new ProjectForm(new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    }, 'form');

    $form->client_name = 'Acme Corp';

    $request = StoreProjectRequest::create('/api/v1/projects', 'POST', ['client_name' => 'Acme Corp']);

    // Loose equality, so two separately built Unique rules scoped to the same client match.
    // It is also what would catch a closure-based scope: two closures are never equal.
    expect($request->rules())->toEqual($form->rules());
});

test('the list query count does not grow with the number of projects', function () {
    apiUserWith('projects.view');
    apiProject();

    // Discarded: the first request also loads the permission cache, which would show up as
    // a difference that has nothing to do with how many rows were serialized.
    countQueriesListing();

    $one = countQueriesListing();

    Project::factory()->for(Client::factory())->count(19)->create();

    expect(countQueriesListing())->toBe($one);
});

/**
 * Count the queries one call to the list endpoint issues.
 */
function countQueriesListing(): int
{
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    test()->getJson('/api/v1/projects')->assertOk();

    return $queries;
}
