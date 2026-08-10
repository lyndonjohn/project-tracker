<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function actingAsDeleter(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

test('the delete modal stays closed until a project is chosen', function () {
    actingAsDeleter('projects.view', 'projects.delete');

    Livewire::test('pages::projects.index')
        ->assertSet('show_delete', false)
        ->assertSet('deleting', null);
});

test('confirming a delete names the project in the modal', function () {
    actingAsDeleter('projects.view', 'projects.delete');
    $project = Project::factory()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->assertSet('show_delete', true)
        ->assertSet('deleting', $project->id)
        ->assertSee('Delete project?')
        ->assertSee('Website Redesign');
});

test('deleting soft deletes the project and records who did it', function () {
    $user = actingAsDeleter('projects.view', 'projects.delete');
    $project = Project::factory()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->call('deleteProject')
        ->assertHasNoErrors()
        ->assertSet('show_delete', false)
        ->assertSet('deleting', null)
        ->assertDontSee('Website Redesign');

    expect(Project::count())->toBe(0)
        ->and(Project::withTrashed()->sole()->deletedBy->is($user))->toBeTrue();
});

test('a deleted project comes back when deleted projects are included', function () {
    actingAsDeleter('projects.view', 'projects.delete');
    $project = Project::factory()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->call('deleteProject')
        ->set('with_deleted', true)
        ->assertSee('Website Redesign');
});

test('deleting is refused for a user without the permission', function () {
    actingAsDeleter('projects.view');
    $project = Project::factory()->create();

    Livewire::test('pages::projects.index')
        ->call('confirmDelete', $project->id)
        ->call('deleteProject')
        ->assertForbidden();

    expect($project->fresh()->trashed())->toBeFalse();
});

test('the delete button is only rendered for a user who can delete', function () {
    actingAsDeleter('projects.view');
    Project::factory()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->assertSee('Website Redesign')
        ->assertDontSeeHtml('data-test="delete-button"');

    actingAsDeleter('projects.view', 'projects.delete');

    Livewire::test('pages::projects.index')->assertSeeHtml('data-test="delete-button"');
});

test('a deleted project offers restore rather than delete', function () {
    actingAsDeleter('projects.view', 'projects.delete', 'projects.restore');
    Project::factory()->deletedBy()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->set('with_deleted', true)
        ->assertSeeHtml('data-test="restore-button"')
        ->assertDontSeeHtml('data-test="delete-button"');
});
