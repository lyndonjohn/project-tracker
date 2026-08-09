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
function actingAsCreator(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

test('a guest is redirected away from the create page', function () {
    $this->get(route('projects.create'))->assertRedirect(route('login'));
});

test('a user without the create permission cannot open the create page', function () {
    actingAsCreator('projects.view');

    $this->get(route('projects.create'))->assertForbidden();
});

test('a user with the create permission sees the form', function () {
    actingAsCreator('projects.view', 'projects.create');

    $this->get(route('projects.create'))
        ->assertOk()
        ->assertSee('New project')
        ->assertSee('Client')
        ->assertSee('Project name');
});

test('the start date is prefilled with today', function () {
    actingAsCreator('projects.view', 'projects.create');

    Livewire::test('pages::projects.create')
        ->assertSet('form.start_date', now()->toDateString());
});

test('existing client names are offered as suggestions', function () {
    actingAsCreator('projects.view', 'projects.create');
    Client::factory()->named('Acme Corp')->create();

    Livewire::test('pages::projects.create')->assertSee('Acme Corp');
});

test('the page creates a project and returns to the list', function () {
    actingAsCreator('projects.view', 'projects.create');

    Livewire::test('pages::projects.create')
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.status', ProjectStatus::Planning->value)
        ->set('form.priority', ProjectPriority::High->value)
        ->set('form.start_date', '2026-01-15')
        ->set('form.due_date', '2026-03-01')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.index'));

    $project = Project::sole();

    expect($project->name)->toBe('Website Redesign')
        ->and($project->client->name)->toBe('Acme Corp')
        ->and($project->status)->toBe(ProjectStatus::Planning)
        ->and($project->priority)->toBe(ProjectPriority::High);
});

test('the page renders validation errors instead of redirecting', function () {
    actingAsCreator('projects.view', 'projects.create');

    Livewire::test('pages::projects.create')
        ->set('form.client_name', '')
        ->set('form.name', '')
        ->call('save')
        ->assertHasErrors(['form.client_name', 'form.name'])
        ->assertNoRedirect();

    expect(Project::count())->toBe(0);
});

test('saving is refused for a user without the create permission', function () {
    actingAsCreator('projects.view');

    Livewire::test('pages::projects.create')
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->call('save')
        ->assertForbidden();

    expect(Project::count())->toBe(0);
});
