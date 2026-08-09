<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Act as a user whose role grants exactly the given permissions.
 */
function actingAsEditor(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

test('a guest is redirected away from the edit page', function () {
    $project = Project::factory()->create();

    $this->get(route('projects.edit', $project))->assertRedirect(route('login'));
});

test('a user without the edit permission cannot open the edit page', function () {
    actingAsEditor('projects.view');
    $project = Project::factory()->create();

    $this->get(route('projects.edit', $project))->assertForbidden();
});

test('the edit page is filled with the project it opens', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $client = Client::factory()->named('Acme Corp')->create();
    $project = Project::factory()->for($client)->create([
        'name' => 'Website Redesign',
        'priority' => ProjectPriority::Low,
        'start_date' => '2026-01-15',
        'due_date' => '2026-03-01',
    ]);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('Website Redesign')
        ->assertSee('Acme Corp');

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->assertSet('form.name', 'Website Redesign')
        ->assertSet('form.client_name', 'Acme Corp')
        ->assertSet('form.priority', ProjectPriority::Low->value)
        ->assertSet('form.version', 1);
});

test('the edit page never offers a status field', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->inProgress()->create();

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertDontSee('In progress');
});

test('the page saves changes and returns to the list', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->create(['name' => 'Old Name']);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('form.name', 'New Name')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.index'));

    expect($project->fresh()->name)->toBe('New Name');
});

test('a stale save is reported without losing what was typed', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->create(['name' => 'Original']);

    $component = Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('form.name', 'Losing Edit');

    Project::whereKey($project->getKey())->update(['name' => 'Winning Edit', 'version' => 2]);

    $component
        ->call('save')
        ->assertOk()
        ->assertNoRedirect()
        ->assertSet('form.name', 'Losing Edit');

    expect($project->fresh()->name)->toBe('Winning Edit');
});

test('the page renders validation errors instead of redirecting', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->create();

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('form.name', '')
        ->call('save')
        ->assertHasErrors(['form.name' => 'required'])
        ->assertNoRedirect();
});

test('saving is refused for a user without the edit permission', function () {
    actingAsEditor('projects.view');
    $project = Project::factory()->create(['name' => 'Original']);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('form.name', 'Nope')
        ->call('save')
        ->assertForbidden();

    expect($project->fresh()->name)->toBe('Original');
});

test('a project that does not exist is a not found response', function () {
    actingAsEditor('projects.view', 'projects.edit-details');

    $this->get(route('projects.edit', 9999))->assertNotFound();
});

test('a soft deleted project cannot be opened for editing', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->deletedBy()->create();

    $this->get(route('projects.edit', $project))->assertNotFound();
});

test('the status the project already has is left alone by an edit', function () {
    actingAsEditor('projects.view', 'projects.edit-details');
    $project = Project::factory()->inProgress()->create();

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('form.name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::InProgress);
});
