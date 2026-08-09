<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Validation\ProjectRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Act as a user whose role grants exactly the given permissions.
 */
function actingAsUserAllowed(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

/**
 * Test the index page component.
 */
function indexPage(array $queryString = []): Testable
{
    return Livewire::withQueryParams($queryString)->test('pages::projects.index');
}

test('a guest is redirected away from the project list', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('a user without the view permission cannot open the project list', function () {
    actingAsUserAllowed();

    $this->get(route('projects.index'))->assertForbidden();
});

test('a user with the view permission sees the project list', function () {
    actingAsUserAllowed('projects.view');
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Website Redesign')
        ->assertSee('Acme Corp');
});

test('the list shows active projects and hides deleted ones by default', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->create(['name' => 'Live Project']);
    Project::factory()->deletedBy()->create(['name' => 'Deleted Project']);

    indexPage()
        ->assertSee('Live Project')
        ->assertDontSee('Deleted Project');
});

test('the include deleted toggle brings deleted projects back into the list', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->deletedBy()->create(['name' => 'Deleted Project']);

    indexPage()
        ->set('with_deleted', true)
        ->assertSee('Deleted Project');
});

test('the search matches project name, client name, and description', function (string $term) {
    actingAsUserAllowed('projects.view');
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create([
        'name' => 'Website Redesign',
        'description' => 'A new marketing site.',
    ]);
    Project::factory()->create(['name' => 'Unrelated Project', 'description' => null]);

    indexPage()
        ->set('search', $term)
        ->assertSee('Website Redesign')
        ->assertDontSee('Unrelated Project');
})->with(['Website', 'Acme', 'marketing']);

test('the status and priority filters narrow the list', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->onHold()->create(['name' => 'Paused Project', 'priority' => ProjectPriority::Low]);
    Project::factory()->planning()->create(['name' => 'Planned Project', 'priority' => ProjectPriority::High]);

    indexPage()
        ->set('status', ProjectStatus::OnHold->value)
        ->assertSee('Paused Project')
        ->assertDontSee('Planned Project');

    indexPage()
        ->set('priority', ProjectPriority::High->value)
        ->assertSee('Planned Project')
        ->assertDontSee('Paused Project');
});

test('sorting by a whitelisted column orders the list and toggles direction', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->create(['name' => 'Zulu']);

    indexPage()
        ->call('sortBy', 'name')
        ->assertSet('sort', 'name')
        ->assertSet('direction', 'asc')
        ->assertSeeInOrder(['Alpha', 'Zulu'])
        ->call('sortBy', 'name')
        ->assertSet('direction', 'desc')
        ->assertSeeInOrder(['Zulu', 'Alpha']);
});

test('sorting by a column outside the whitelist falls back to the default', function (string $column) {
    actingAsUserAllowed('projects.view');
    Project::factory()->create(['name' => 'Alpha']);

    indexPage()
        ->call('sortBy', $column)
        ->assertOk()
        ->assertSet('sort', 'created_at')
        ->assertSet('direction', 'desc')
        ->assertSee('Alpha');
})->with([
    'password',
    'name); drop table projects;--',
    'id',
]);

test('tampered listing state in the url is reset to its default', function (string $property, mixed $value, mixed $default) {
    actingAsUserAllowed('projects.view');
    Project::factory()->create(['name' => 'Alpha']);

    indexPage([$property => $value])
        ->assertOk()
        ->assertSet($property, $default)
        ->assertHasNoErrors()
        ->assertSee('Alpha');
})->with([
    ['status', 'archived', null],
    ['priority', 'urgent', null],
    ['sort', 'password', 'created_at'],
    ['direction', 'sideways', 'desc'],
    ['per_page', 5000, 25],
]);

test('valid listing state in the url is kept', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->onHold()->create(['name' => 'Paused Project']);
    Project::factory()->planning()->create(['name' => 'Planned Project']);

    indexPage(['status' => ProjectStatus::OnHold->value, 'sort' => 'name', 'direction' => 'asc'])
        ->assertSet('status', ProjectStatus::OnHold->value)
        ->assertSet('sort', 'name')
        ->assertSee('Paused Project')
        ->assertDontSee('Planned Project');
});

test('the list issues the same number of queries however many projects it renders', function () {
    actingAsUserAllowed('projects.view');

    Project::factory()->create();

    countQueriesRendering();
    $queriesForOne = countQueriesRendering();

    Project::factory()->count(19)->create();
    $queriesForTwenty = countQueriesRendering();

    expect($queriesForTwenty)->toBe($queriesForOne);
});

test('a user with the restore permission can restore a deleted project', function () {
    $user = actingAsUserAllowed('projects.view', 'projects.restore');
    $project = Project::factory()->deletedBy($user)->create(['name' => 'Deleted Project']);

    indexPage()
        ->set('with_deleted', true)
        ->call('restore', $project->id)
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->trashed())->toBeFalse()
        ->and($project->deleted_by)->toBeNull();
});

test('restoring is refused for a user without the permission', function () {
    actingAsUserAllowed('projects.view');
    $project = Project::factory()->deletedBy()->create();

    indexPage()->call('restore', $project->id)->assertForbidden();

    expect($project->fresh()->trashed())->toBeTrue();
});

test('the restore button is not rendered for a user who cannot restore', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->deletedBy()->create(['name' => 'Deleted Project']);

    indexPage()
        ->set('with_deleted', true)
        ->assertSee('Deleted Project')
        ->assertDontSee('Restore');
});

test('the new project button is only rendered for a user who can create one', function () {
    actingAsUserAllowed('projects.view');

    indexPage()->assertDontSee('New project');

    actingAsUserAllowed('projects.view', 'projects.create');

    indexPage()->assertSee('New project');
});

test('the sort whitelist is the one on ProjectRules', function () {
    actingAsUserAllowed('projects.view');
    Project::factory()->create();

    foreach (ProjectRules::SORTABLE as $column) {
        indexPage()->call('sortBy', $column)->assertSet('sort', $column);
    }
});

/**
 * Count the queries a full render of the index page issues.
 *
 * Call it once and discard the result before measuring: the first render of a request
 * also loads the permission cache, which would otherwise show up as a difference that
 * has nothing to do with how many rows were rendered.
 */
function countQueriesRendering(): int
{
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::test('pages::projects.index')->assertOk();

    return $queries;
}
