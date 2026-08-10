<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function actingAsStatusChanger(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

function statusModal(?Project $project = null): Testable
{
    $component = Livewire::test('pages::projects.status-modal');

    return $project === null ? $component : $component->call('open', $project->id);
}

test('the modal stays closed until it is opened for a project', function () {
    actingAsStatusChanger('projects.update-status');

    statusModal()->assertSet('show', false);
});

test('opening the modal fills the form from the project', function () {
    actingAsStatusChanger('projects.update-status');
    $this->freezeTime();
    $project = Project::factory()->planning()->create(['name' => 'Website Redesign']);

    statusModal($project)
        ->assertSet('show', true)
        ->assertSet('form.status', ProjectStatus::Planning->value)
        ->assertSet('form.changed_at', now()->format('Y-m-d\TH:i'))
        ->assertSet('form.version', 1)
        ->assertSee('Website Redesign');
});

test('a project with no status shows a placeholder rather than one it does not have', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->create(['status' => null]);

    statusModal($project)
        ->assertSet('form.status', null)
        ->assertSee('Choose a status')
        ->assertDontSeeHtml('disabled selected');
});

test('the empty status option is selectable, so the browser can match a null status', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->create(['status' => null]);

    preg_match_all('/<option[^>]*>/', statusModal($project)->html(), $options);

    $empty = collect($options[0])->first(fn (string $option) => str_contains($option, 'value=""'));

    expect($empty)->not->toBeNull()
        ->and($empty)->not->toContain('disabled');
});

test('a project with no status can be given one', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->create(['status' => null]);

    statusModal($project)
        ->set('form.status', ProjectStatus::Planning->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false);

    expect($project->fresh()->status)->toBe(ProjectStatus::Planning);
});

test('the timestamp is only confirmed for the statuses that write one', function (string $status, bool $expected) {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->planning()->create();

    $component = statusModal($project)->set('form.status', $status);

    expect($component->instance()->stampsATimestamp())->toBe($expected);
})->with([
    [ProjectStatus::InProgress->value, true],
    [ProjectStatus::Completed->value, true],
    [ProjectStatus::Planning->value, false],
    [ProjectStatus::OnHold->value, false],
]);

test('saving moves the project and stamps the timestamp the status owns', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->planning()->create();

    statusModal($project)
        ->set('form.status', ProjectStatus::InProgress->value)
        ->set('form.changed_at', '2026-02-01T09:30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('project-updated');

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::InProgress)
        ->and($project->started_at->format('Y-m-d H:i'))->toBe('2026-02-01 09:30')
        ->and($project->completed_at)->toBeNull()
        ->and($project->version)->toBe(2);
});

test('completing a project stamps completed_at and leaves started_at alone', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->inProgress()->create(['started_at' => '2026-01-10 09:00:00']);

    statusModal($project)
        ->set('form.status', ProjectStatus::Completed->value)
        ->set('form.changed_at', '2026-03-01T17:00')
        ->call('save')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->completed_at->format('Y-m-d H:i'))->toBe('2026-03-01 17:00')
        ->and($project->started_at->toDateTimeString())->toBe('2026-01-10 09:00:00');
});

test('completing a project before it started is rejected and the modal stays open', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->inProgress()->create(['started_at' => '2026-01-10 09:00:00']);

    statusModal($project)
        ->set('form.status', ProjectStatus::Completed->value)
        ->set('form.changed_at', '2026-01-09T09:00')
        ->call('save')
        ->assertHasErrors(['form.changed_at' => 'after_or_equal'])
        ->assertSet('show', true);

    expect($project->fresh()->status)->toBe(ProjectStatus::InProgress);
});

test('starting a project after it was completed is rejected', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->completed()->create([
        'started_at' => '2026-01-10 09:00:00',
        'completed_at' => '2026-01-20 09:00:00',
    ]);

    statusModal($project)
        ->set('form.status', ProjectStatus::InProgress->value)
        ->set('form.changed_at', '2026-01-21T09:00')
        ->call('save')
        ->assertHasErrors(['form.changed_at' => 'before_or_equal']);
});

test('a status change made against an out of date version is reported, not applied', function () {
    actingAsStatusChanger('projects.update-status');
    $project = Project::factory()->planning()->create();

    $component = statusModal($project)->set('form.status', ProjectStatus::Completed->value);

    Project::whereKey($project->getKey())->update([
        'status' => ProjectStatus::OnHold->value,
        'version' => 2,
    ]);

    $component->call('save')->assertHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold);
});

test('saving is refused for a user without the permission', function () {
    actingAsStatusChanger('projects.view');
    $project = Project::factory()->planning()->create();

    statusModal($project)
        ->set('form.status', ProjectStatus::Completed->value)
        ->call('save')
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Planning);
});

test('the status button is only rendered for a user who can change status', function () {
    actingAsStatusChanger('projects.view');
    Project::factory()->create(['name' => 'Website Redesign']);

    Livewire::test('pages::projects.index')
        ->assertSee('Website Redesign')
        ->assertDontSeeHtml('data-test="status-button"');

    actingAsStatusChanger('projects.view', 'projects.update-status');

    Livewire::test('pages::projects.index')->assertSeeHtml('data-test="status-button"');
});
