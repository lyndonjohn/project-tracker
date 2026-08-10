<?php

namespace App\Validation;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Livewire form objects and API Form Requests are bound to incompatible base classes, so
 * this is the only place a rule can be defined once for both.
 */
final class ProjectRules
{
    /**
     * orderBy() interpolates identifiers rather than binding them, so a sort column has to
     * come from a fixed list. Project::sorted() turns each entry here into an ordering,
     * including "client_name", which is not a column on projects.
     *
     * @var list<string>
     */
    public const array SORTABLE = ['name', 'client_name', 'due_date', 'status', 'priority', 'created_at'];

    /** @var list<string> */
    public const array DIRECTIONS = ['asc', 'desc'];

    /**
     * Pass the project being edited so the unique rule ignores itself. Status is accepted
     * on create only; after that it moves through status(), which also stamps a timestamp.
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
     * The changed_at bound is symmetric because transitions are unrestricted, so either
     * timestamp can be the one being written and the other the one to stay behind.
     *
     * $target is unvalidated input; a value that is not a status adds no bound and is
     * rejected by the enum rule below.
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
     * The optimistic-locking counter, which only the API validates: a Livewire form holds
     * it as a property loaded from the model, while an API client has to send back the
     * version it read or the write cannot be checked for staleness at all.
     *
     * @return array<string, list<mixed>>
     */
    public static function version(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function listing(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'priority' => ['nullable', Rule::enum(ProjectPriority::class)],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'with_deleted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.unique' => 'This client already has a project with that name. If it was deleted, restore that project instead of creating a new one.',
            'due_date.after_or_equal' => 'The due date must be on or after the start date.',
            'changed_at.after_or_equal' => 'The completion time must be on or after the project was started.',
            'changed_at.before_or_equal' => 'The start time must be on or before the project was completed.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'client_name' => 'client name',
            'start_date' => 'start date',
            'due_date' => 'due date',
            'due_from' => 'due date from',
            'due_to' => 'due date to',
            'changed_at' => 'time',
            'per_page' => 'page size',
            'with_deleted' => 'include deleted',
        ];
    }

    /**
     * Keep the scalar where(): a closure does not serialize into the rule string, so a
     * test comparing the two surfaces' rules would stop covering the client scoping.
     *
     * Soft-deleted projects count. A trashed project still holds its name, so allowing a
     * live duplicate would break the index the moment someone restored it.
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
