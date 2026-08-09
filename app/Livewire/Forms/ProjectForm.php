<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Services\ProjectService;
use App\Validation\ProjectRules;
use Illuminate\Support\Facades\Gate;
use Livewire\Form;
use LogicException;

/**
 * The create and edit form for a project.
 *
 * Every rule, message, and label comes from ProjectRules, which the API Form Requests
 * call too, so the two surfaces cannot drift apart.
 */
class ProjectForm extends Form
{
    public ?Project $project = null;

    public string $client_name = '';

    public string $name = '';

    public ?string $description = null;

    public ?string $status = null;

    public ?string $priority = null;

    public string $start_date = '';

    public ?string $due_date = null;

    /**
     * The version of the project this form was loaded from.
     *
     * Sent back on save so a write lands only if nobody else saved in the meantime.
     */
    public int $version = 1;

    /**
     * Fill the form from an existing project.
     */
    public function setProject(Project $project): void
    {
        $this->project = $project;
        $this->client_name = $project->client->name;
        $this->name = $project->name;
        $this->description = $project->description;
        $this->status = $project->status?->value;
        $this->priority = $project->priority?->value;
        $this->start_date = $project->start_date->toDateString();
        $this->due_date = $project->due_date?->toDateString();
        $this->version = $project->version;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ProjectRules::details($this->client_name, $this->project);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ProjectRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return ProjectRules::attributes();
    }

    /**
     * Create the project this form describes.
     *
     * Authorization runs before validation so a user who cannot create a project is
     * refused outright rather than being told which names are already taken.
     */
    public function store(): Project
    {
        Gate::authorize('projects.create');

        return app(ProjectService::class)->create($this->validate());
    }

    /**
     * Save changes to the project this form was loaded from.
     *
     * The form is refilled from the saved project so its version matches the row again
     * and a second save in the same sitting is not rejected as stale.
     */
    public function update(): Project
    {
        Gate::authorize('projects.edit-details');

        $project = app(ProjectService::class)->updateDetails(
            $this->project ?? throw new LogicException('The form has no project to update; call setProject() first.'),
            $this->validate(),
            $this->version,
        );

        $this->setProject($project);

        return $project;
    }
}
