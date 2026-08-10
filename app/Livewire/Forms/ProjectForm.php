<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Services\ProjectService;
use App\Validation\ProjectRules;
use Illuminate\Support\Facades\Gate;
use Livewire\Form;
use LogicException;

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

    /** Sent back on save so a write lands only if nobody else saved in the meantime. */
    public int $version = 1;

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
     * The optional fields, which a select or a cleared input hands back as "".
     *
     * @var list<string>
     */
    private const array NULLABLE = ['description', 'status', 'priority', 'due_date'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ProjectRules::details($this->client_name, $this->project);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProjectRules::messages();
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ProjectRules::attributes();
    }

    /**
     * Authorize before validating, so someone who cannot create a project is refused
     * outright rather than told which names are taken.
     */
    public function store(): Project
    {
        Gate::authorize('projects.create');

        $this->blankOptionalsToNull();

        return app(ProjectService::class)->create($this->validate());
    }

    /**
     * Refills from the saved project so a second save in the same sitting is not stale.
     */
    public function update(): Project
    {
        Gate::authorize('projects.edit-details');

        $this->blankOptionalsToNull();

        $project = app(ProjectService::class)->updateDetails(
            $this->project ?? throw new LogicException('The form has no project to update; call setProject() first.'),
            $this->validate(),
            $this->version,
        );

        $this->setProject($project);

        return $project;
    }

    /**
     * Treat a blank optional field as absent, the way ConvertEmptyStringsToNull does for
     * HTTP requests — Livewire properties never pass through that middleware.
     *
     * This is not cosmetic. Laravel skips non-implicit rules for a blank string
     * (Validator::presentOrRuleIsImplicit), so "" walks past nullable+Rule::enum and only
     * fails later, in the enum cast, as a ValueError the user sees as a generic failure.
     */
    private function blankOptionalsToNull(): void
    {
        foreach (self::NULLABLE as $property) {
            if ($this->{$property} === '') {
                $this->{$property} = null;
            }
        }
    }
}
