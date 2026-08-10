<?php

namespace App\Livewire\Forms;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ProjectService;
use App\Validation\ProjectRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Form;
use LogicException;

/**
 * Separate from ProjectForm because a status change also writes a timestamp, so it is
 * confirmed on its own and carries its own permission.
 */
class ProjectStatusForm extends Form
{
    public ?Project $project = null;

    public ?string $status = null;

    public string $changed_at = '';

    /** Sent back on save so a write lands only if nobody else saved in the meantime. */
    public int $version = 1;

    public function setProject(Project $project): void
    {
        $this->project = $project;
        $this->status = $project->status?->value;
        $this->changed_at = CarbonImmutable::now()->format('Y-m-d\TH:i');
        $this->version = $project->version;
    }

    /**
     * The changed_at bound depends on the status being moved to, so the submitted value
     * is passed through unvalidated; the enum rule is what rejects a bad one.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ProjectRules::status($this->requireProject(), ProjectStatus::tryFrom($this->status ?? ''));
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

    public function save(): Project
    {
        Gate::authorize('projects.update-status');

        $validated = $this->validate();

        $project = app(ProjectService::class)->updateStatus(
            $this->requireProject(),
            ProjectStatus::from($validated['status']),
            CarbonImmutable::parse($validated['changed_at']),
            $this->version,
        );

        $this->setProject($project);

        return $project;
    }

    private function requireProject(): Project
    {
        return $this->project ?? throw new LogicException('The form has no project; call setProject() first.');
    }
}
