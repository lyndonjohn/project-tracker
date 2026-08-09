<?php

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Validation\ProjectRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;

/**
 * Validate a set of project details against the rules, returning the validator.
 */
function validateDetails(array $data, ?Project $project = null): Illuminate\Validation\Validator
{
    $data += [
        'client_name' => 'Acme Corp',
        'name' => 'Website Redesign',
        'start_date' => '2026-01-15',
    ];

    return Validator::make(
        $data,
        ProjectRules::details($data['client_name'], $project),
        ProjectRules::messages(),
        ProjectRules::attributes(),
    );
}

/**
 * Validate a set of listing query parameters against the rules.
 */
function validateListing(array $data): Illuminate\Validation\Validator
{
    return Validator::make($data, ProjectRules::listing(), ProjectRules::messages(), ProjectRules::attributes());
}

test('two clients may each have a project with the same name', function () {
    $client = Client::factory()->named('Globex')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);
    Client::factory()->named('Acme Corp')->create();

    expect(validateDetails(['name' => 'Website Redesign'])->passes())->toBeTrue();
});

test('one client may not have two projects with the same name', function () {
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    $validator = validateDetails(['name' => 'Website Redesign']);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))
        ->toBe('This client already has a project with that name. If it was deleted, restore that project instead of creating a new one.');
});

test('a name is matched against the client the project will actually be saved to', function () {
    $client = Client::factory()->named('Acme Corp')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    expect(validateDetails(['client_name' => '  ACME   corp ', 'name' => 'Website Redesign'])->passes())->toBeFalse();
});

test('a name held by a soft deleted project of the same client is still rejected', function () {
    $client = Client::factory()->named('Acme Corp')->create();
    $project = Project::factory()->for($client)->create(['name' => 'Website Redesign']);
    $project->delete();

    expect(validateDetails(['name' => 'Website Redesign'])->passes())->toBeFalse();
});

test('a project keeps its own name when edited', function () {
    $client = Client::factory()->named('Acme Corp')->create();
    $project = Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    expect(validateDetails(['name' => 'Website Redesign'], $project)->passes())->toBeTrue();
});

test('a name is available under a client that does not exist yet', function () {
    $client = Client::factory()->named('Globex')->create();
    Project::factory()->for($client)->create(['name' => 'Website Redesign']);

    expect(validateDetails(['client_name' => 'Brand New Client', 'name' => 'Website Redesign'])->passes())->toBeTrue();
});

test('the unique rule scopes to the client in a form a rule comparison can see', function () {
    $client = Client::factory()->named('Acme Corp')->create();

    $rule = collect(ProjectRules::details('Acme Corp')['name'])
        ->first(fn ($rule) => $rule instanceof Unique);

    expect((string) $rule)->toContain('client_id')
        ->toContain((string) $client->id);
});

test('a due date before the start date is rejected', function () {
    $validator = validateDetails(['start_date' => '2026-01-15', 'due_date' => '2026-01-14']);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('due_date'))->toBe('The due date must be on or after the start date.');
});

test('a due date on the start date is accepted', function () {
    expect(validateDetails(['start_date' => '2026-01-15', 'due_date' => '2026-01-15'])->passes())->toBeTrue();
});

test('an unknown status or priority is rejected', function () {
    $validator = validateDetails(['status' => 'archived', 'priority' => 'urgent']);

    expect($validator->errors()->keys())->toEqualCanonicalizing(['status', 'priority']);
});

test('status is only accepted when creating a project', function () {
    $project = Project::factory()->create();

    expect(ProjectRules::details('Acme Corp'))->toHaveKey('status')
        ->and(ProjectRules::details('Acme Corp', $project))->not->toHaveKey('status');
});

test('a missing client name does not blow up the unique rule', function () {
    $validator = Validator::make(
        ['name' => 'Website Redesign', 'start_date' => '2026-01-15'],
        ProjectRules::details(null),
    );

    expect($validator->errors()->keys())->toBe(['client_name']);
});

test('completing a project earlier than it started is rejected', function () {
    $project = Project::factory()->inProgress()->create(['started_at' => '2026-01-10 09:00:00']);

    $validator = Validator::make(
        ['status' => ProjectStatus::Completed->value, 'changed_at' => '2026-01-09 09:00:00'],
        ProjectRules::status($project, ProjectStatus::Completed),
        ProjectRules::messages(),
        ProjectRules::attributes(),
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('changed_at'))
        ->toBe('The completion time must be on or after the project was started.');
});

test('starting a project later than it was completed is rejected', function () {
    $project = Project::factory()->completed()->create([
        'started_at' => '2026-01-10 09:00:00',
        'completed_at' => '2026-01-20 09:00:00',
    ]);

    $validator = Validator::make(
        ['status' => ProjectStatus::InProgress->value, 'changed_at' => '2026-01-21 09:00:00'],
        ProjectRules::status($project, ProjectStatus::InProgress),
        ProjectRules::messages(),
        ProjectRules::attributes(),
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('changed_at'))
        ->toBe('The start time must be on or before the project was completed.');
});

test('a project that has never been completed may be started at any time', function () {
    $project = Project::factory()->planning()->create();

    $validator = Validator::make(
        ['status' => ProjectStatus::InProgress->value, 'changed_at' => '2030-01-01 09:00:00'],
        ProjectRules::status($project, ProjectStatus::InProgress),
    );

    expect($validator->passes())->toBeTrue();
});

test('a status change that writes no timestamp is not bound by the stored ones', function () {
    $project = Project::factory()->completed()->create([
        'started_at' => '2026-01-10 09:00:00',
        'completed_at' => '2026-01-20 09:00:00',
    ]);

    $validator = Validator::make(
        ['status' => ProjectStatus::OnHold->value, 'changed_at' => '2026-02-01 09:00:00'],
        ProjectRules::status($project, ProjectStatus::OnHold),
    );

    expect($validator->passes())->toBeTrue();
});

test('a status change requires a real status and a time', function () {
    $project = Project::factory()->create();

    $validator = Validator::make([], ProjectRules::status($project));

    expect($validator->errors()->keys())->toEqualCanonicalizing(['status', 'changed_at']);
});

test('every whitelisted sort column is accepted', function (string $column) {
    expect(validateListing(['sort' => $column])->passes())->toBeTrue();
})->with(ProjectRules::SORTABLE);

test('a sort column outside the whitelist is rejected', function (string $column) {
    expect(validateListing(['sort' => $column])->passes())->toBeFalse();
})->with([
    'password',
    'name); drop table projects;--',
    'projects.name',
    'id',
]);

test('a sort direction outside the whitelist is rejected', function () {
    expect(validateListing(['direction' => 'asc'])->passes())->toBeTrue()
        ->and(validateListing(['direction' => 'sideways'])->passes())->toBeFalse();
});

test('a page size above the cap is rejected', function () {
    expect(validateListing(['per_page' => 100])->passes())->toBeTrue()
        ->and(validateListing(['per_page' => 101])->passes())->toBeFalse()
        ->and(validateListing(['per_page' => 0])->passes())->toBeFalse();
});

test('listing filters only accept real statuses and priorities', function () {
    expect(validateListing(['status' => ProjectStatus::OnHold->value])->passes())->toBeTrue()
        ->and(validateListing(['status' => 'archived'])->passes())->toBeFalse()
        ->and(validateListing(['priority' => 'urgent'])->passes())->toBeFalse();
});

test('an empty listing query is valid', function () {
    expect(validateListing([])->passes())->toBeTrue();
});
