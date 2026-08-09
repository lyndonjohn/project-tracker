<?php

namespace App\Validation;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Every validation rule for projects, defined exactly once.
 *
 * Livewire form objects extend Livewire\Form and Form Requests extend FormRequest, so
 * neither can inherit from the other. Both call this class instead, which keeps the web
 * UI and the API from drifting apart the first time a rule is edited.
 */
final class ProjectRules
{
    /**
     * The columns a project list may be sorted by.
     *
     * orderBy() interpolates identifiers rather than binding them, so a sort column must
     * come from a fixed list. Both surfaces validate against this one constant, so a new
     * sortable column cannot be enabled on one of them and not the other.
     *
     * @var list<string>
     */
    public const array SORTABLE = ['name', 'due_date', 'status', 'priority', 'created_at'];

    /**
     * The directions a project list may be sorted in.
     *
     * @var list<string>
     */
    public const array DIRECTIONS = ['asc', 'desc'];

    /**
     * Rules for creating and editing a project.
     *
     * Pass the project being edited so the unique rule ignores itself. Status is only
     * accepted on create — it is changed through the status modal and its endpoint after
     * that, because those also write the started_at / completed_at timestamps.
     *
     * @return array<string, list<mixed>>
     */
    public static function details(?string $clientName, ?Project $project = null): array
    {
        $rules = [
            'client_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', self::uniqueNamePerClient($clientName, $project)],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::enum(ProjectPriority::class)],
            'start_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];

        if ($project === null) {
            $rules['status'] = ['nullable', Rule::enum(ProjectStatus::class)];
        }

        return $rules;
    }

    /**
     * Rules for a status change.
     *
     * The bound on changed_at is symmetric because the invariant is
     * "completed_at >= started_at" and transitions are unrestricted, so either timestamp
     * can be the one being written. Each bound only applies when the other timestamp is
     * already stored, so a project that has never been completed moves freely.
     *
     * The target status is read from the unvalidated input; when it is not a real status
     * no bound is added and the enum rule below rejects the request anyway.
     *
     * @return array<string, list<mixed>>
     */
    public static function status(Project $project, ?ProjectStatus $target = null): array
    {
        $changedAt = ['required', 'date'];

        if ($target === ProjectStatus::InProgress && $project->completed_at !== null) {
            $changedAt[] = 'before_or_equal:'.$project->completed_at->toDateTimeString();
        }

        if ($target === ProjectStatus::Completed && $project->started_at !== null) {
            $changedAt[] = 'after_or_equal:'.$project->started_at->toDateTimeString();
        }

        return [
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'changed_at' => $changedAt,
        ];
    }

    /**
     * Rules for the query parameters of a project list, on both surfaces.
     *
     * @return array<string, list<mixed>>
     */
    public static function listing(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'priority' => ['nullable', Rule::enum(ProjectPriority::class)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'with_deleted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * Message overrides for the rules whose stock wording leaks schema detail or reads awkwardly.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.unique' => 'This client already has a project with that name. If it was deleted, restore that project instead of creating a new one.',
            'due_date.after_or_equal' => 'The due date must be on or after the start date.',
            'changed_at.after_or_equal' => 'The completion time must be on or after the project was started.',
            'changed_at.before_or_equal' => 'The start time must be on or before the project was completed.',
        ];
    }

    /**
     * Field labels, so messages read "client name" rather than "client name field".
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'client_name' => 'client name',
            'start_date' => 'start date',
            'due_date' => 'due date',
            'changed_at' => 'time',
            'per_page' => 'page size',
            'with_deleted' => 'include deleted',
        ];
    }

    /**
     * A project name must be unique within its client, not globally.
     *
     * The client is resolved through the same canonical key the service uses, so a name
     * typed with different casing or spacing scopes to the client it will actually be
     * saved against. For a brand-new client there is no id to scope to, the rule matches
     * no rows, and it passes — correct, since a new client owns no projects yet.
     *
     * The scalar where() form is deliberate: a closure would not serialize into the rule
     * string, so a test comparing the two surfaces' rules would silently stop covering
     * the client scoping.
     *
     * Soft-deleted projects are included on purpose. A trashed project still holds its
     * name, and letting a live duplicate through would corrupt the index the moment
     * someone restored it.
     */
    private static function uniqueNamePerClient(?string $clientName, ?Project $project): Unique
    {
        $clientId = $clientName === null
            ? null
            : Client::where('name_key', Client::keyFor($clientName))->value('id');

        return Rule::unique('projects', 'name')
            ->where('client_id', $clientId)
            ->ignore($project);
    }
}
