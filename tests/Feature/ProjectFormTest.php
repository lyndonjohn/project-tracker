<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Exceptions\StaleProjectException;
use App\Livewire\Forms\ProjectForm;
use App\Livewire\Forms\ProjectStatusForm;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Validation\ProjectRules;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Act as a user whose role grants exactly the given permissions.
 */
function actingAsUserGranted(string ...$permissions): User
{
    $role = Role::create(['name' => 'Test '.Str::random(8)]);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission));
    }

    return tap(User::factory()->create()->assignRole($role), fn (User $user) => test()->actingAs($user));
}

/**
 * A minimal component that hosts both form objects, so they can be exercised on their own
 * before the real pages exist.
 */
function hostComponent(?Project $project = null): Testable
{
    $component = new class extends Component
    {
        public ProjectForm $form;

        public ProjectStatusForm $statusForm;

        public function mount(?Project $project = null): void
        {
            if ($project !== null) {
                $this->form->setProject($project);
                $this->statusForm->setProject($project);
            }
        }

        public function store(): void
        {
            $this->form->store();
        }

        public function update(): void
        {
            $this->form->update();
        }

        public function saveStatus(): void
        {
            $this->statusForm->save();
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return Livewire::test($component, ['project' => $project]);
}

test('the form creates a project and its client', function () {
    actingAsUserGranted('projects.create');

    hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.priority', ProjectPriority::High->value)
        ->set('form.start_date', '2026-01-15')
        ->set('form.due_date', '2026-03-01')
        ->call('store')
        ->assertHasNoErrors();

    $project = Project::sole();

    expect($project->name)->toBe('Website Redesign')
        ->and($project->client->name)->toBe('Acme Corp')
        ->and($project->priority)->toBe(ProjectPriority::High)
        ->and($project->version)->toBe(1);
});

test('an optional field left blank is saved as absent, not as an empty string', function () {
    actingAsUserGranted('projects.create');

    hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->set('form.description', '')
        ->set('form.status', '')
        ->set('form.priority', '')
        ->set('form.due_date', '')
        ->call('store')
        ->assertHasNoErrors();

    $project = Project::sole();

    expect($project->description)->toBeNull()
        ->and($project->status)->toBeNull()
        ->and($project->priority)->toBeNull()
        ->and($project->due_date)->toBeNull();
});

test('clearing an optional field on edit blanks it rather than failing', function () {
    actingAsUserGranted('projects.edit-details');
    $project = Project::factory()->create([
        'description' => 'Something',
        'priority' => ProjectPriority::High,
        'due_date' => '2026-03-01',
    ]);

    hostComponent($project)
        ->set('form.description', '')
        ->set('form.priority', '')
        ->set('form.due_date', '')
        ->call('update')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->description)->toBeNull()
        ->and($project->priority)->toBeNull()
        ->and($project->due_date)->toBeNull();
});

test('the form reports validation errors instead of throwing', function () {
    actingAsUserGranted('projects.create');

    hostComponent()
        ->set('form.client_name', '')
        ->set('form.name', '')
        ->set('form.start_date', '')
        ->call('store')
        ->assertHasErrors([
            'form.client_name' => 'required',
            'form.name' => 'required',
            'form.start_date' => 'required',
        ]);

    expect(Project::count())->toBe(0);
});

test('the form rejects a due date before the start date', function () {
    actingAsUserGranted('projects.create');

    hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->set('form.due_date', '2026-01-14')
        ->call('store')
        ->assertHasErrors(['form.due_date' => 'after_or_equal']);
});

test('the form rejects a name another project of the same client already holds', function () {
    actingAsUserGranted('projects.create');
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->call('store')
        ->assertHasErrors(['form.name' => 'unique']);
});

test('the form uses the shared wording for its messages', function () {
    actingAsUserGranted('projects.create');
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    $errors = hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->call('store')
        ->errors();

    expect($errors->first('form.name'))->toBe(ProjectRules::messages()['name.unique']);
});

test('the form is filled from the project it edits', function () {
    actingAsUserGranted('projects.edit-details');
    $client = Client::factory()->named('Acme Corp')->create();
    $project = Project::factory()->for($client)->create([
        'name' => 'Website Redesign',
        'priority' => ProjectPriority::Low,
        'start_date' => '2026-01-15',
        'due_date' => '2026-03-01',
    ]);

    hostComponent($project)
        ->assertSet('form.client_name', 'Acme Corp')
        ->assertSet('form.name', 'Website Redesign')
        ->assertSet('form.priority', ProjectPriority::Low->value)
        ->assertSet('form.start_date', '2026-01-15')
        ->assertSet('form.due_date', '2026-03-01')
        ->assertSet('form.version', 1);
});

test('the form saves changes to the project it edits', function () {
    actingAsUserGranted('projects.edit-details');
    $project = Project::factory()->create(['name' => 'Old Name']);

    hostComponent($project)
        ->set('form.name', 'New Name')
        ->call('update')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('New Name');
});

test('editing keeps the form usable for a second save', function () {
    actingAsUserGranted('projects.edit-details');
    $project = Project::factory()->create();

    hostComponent($project)
        ->set('form.name', 'First')
        ->call('update')
        ->assertSet('form.version', 2)
        ->set('form.name', 'Second')
        ->call('update')
        ->assertHasNoErrors()
        ->assertSet('form.version', 3);

    expect($project->fresh()->name)->toBe('Second');
});

test('a project keeps its own name when edited', function () {
    actingAsUserGranted('projects.edit-details');
    $project = Project::factory()->create(['name' => 'Website Redesign']);

    hostComponent($project)
        ->set('form.description', 'Now with a description.')
        ->call('update')
        ->assertHasNoErrors();

    expect($project->fresh()->description)->toBe('Now with a description.');
});

test('an edit made against an out of date version is refused', function () {
    actingAsUserGranted('projects.edit-details');
    $project = Project::factory()->create(['name' => 'Original']);

    $component = hostComponent($project)->set('form.name', 'Losing Edit');

    Project::whereKey($project->getKey())->update(['name' => 'Winning Edit', 'version' => 2]);

    expect(fn () => $component->call('update'))->toThrow(StaleProjectException::class);

    expect($project->fresh()->name)->toBe('Winning Edit');
});

test('the status form defaults to the current status and now', function () {
    actingAsUserGranted('projects.update-status');
    $this->freezeTime();
    $project = Project::factory()->planning()->create();

    hostComponent($project)
        ->assertSet('statusForm.status', ProjectStatus::Planning->value)
        ->assertSet('statusForm.changed_at', now()->format('Y-m-d\TH:i'))
        ->assertSet('statusForm.version', 1);
});

test('the status form stamps the timestamp the new status owns', function () {
    actingAsUserGranted('projects.update-status');
    $project = Project::factory()->planning()->create();

    hostComponent($project)
        ->set('statusForm.status', ProjectStatus::InProgress->value)
        ->set('statusForm.changed_at', '2026-02-01 09:30:00')
        ->call('saveStatus')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::InProgress)
        ->and($project->started_at->toDateTimeString())->toBe('2026-02-01 09:30:00')
        ->and($project->completed_at)->toBeNull();
});

test('the status form rejects completing a project before it started', function () {
    actingAsUserGranted('projects.update-status');
    $project = Project::factory()->inProgress()->create(['started_at' => '2026-01-10 09:00:00']);

    hostComponent($project)
        ->set('statusForm.status', ProjectStatus::Completed->value)
        ->set('statusForm.changed_at', '2026-01-09 09:00:00')
        ->call('saveStatus')
        ->assertHasErrors(['statusForm.changed_at' => 'after_or_equal']);
});

test('the status form rejects starting a project after it was completed', function () {
    actingAsUserGranted('projects.update-status');
    $project = Project::factory()->completed()->create([
        'started_at' => '2026-01-10 09:00:00',
        'completed_at' => '2026-01-20 09:00:00',
    ]);

    hostComponent($project)
        ->set('statusForm.status', ProjectStatus::InProgress->value)
        ->set('statusForm.changed_at', '2026-01-21 09:00:00')
        ->call('saveStatus')
        ->assertHasErrors(['statusForm.changed_at' => 'before_or_equal']);
});

test('the status form rejects a status that is not a real status', function () {
    actingAsUserGranted('projects.update-status');
    $project = Project::factory()->create();

    hostComponent($project)
        ->set('statusForm.status', 'archived')
        ->call('saveStatus')
        ->assertHasErrors(['statusForm.status']);
});

test('creating is refused for a user without the permission', function () {
    actingAsUserGranted('projects.view');

    hostComponent()
        ->set('form.client_name', 'Acme Corp')
        ->set('form.name', 'Website Redesign')
        ->set('form.start_date', '2026-01-15')
        ->call('store')
        ->assertForbidden();

    expect(Project::count())->toBe(0);
});

test('editing is refused for a user without the permission', function () {
    actingAsUserGranted('projects.view');
    $project = Project::factory()->create(['name' => 'Original']);

    hostComponent($project)
        ->set('form.name', 'Nope')
        ->call('update')
        ->assertForbidden();

    expect($project->fresh()->name)->toBe('Original');
});

test('changing status is refused for a user without the permission', function () {
    actingAsUserGranted('projects.view');
    $project = Project::factory()->planning()->create();

    hostComponent($project)
        ->set('statusForm.status', ProjectStatus::Completed->value)
        ->call('saveStatus')
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Planning);
});

test('an unauthorized create is refused before the form is validated', function () {
    actingAsUserGranted('projects.view');

    hostComponent()
        ->call('store')
        ->assertForbidden()
        ->assertHasNoErrors();
});
