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
 * The status change form behind the confirmation modal.
 *
 * Status is deliberately not part of ProjectForm: changing it also writes the started_at
 * or completed_at timestamp, which is why it is confirmed separately and carries its own
 * permission.
 */
class ProjectStatusForm extends Form
{
    public ?Project $project = null;

    public ?string $status = null;

    public string $changed_at = '';

    /**
     * The version of the project this form was loaded from.
     */
    public int $version = 1;

    /**
     * Fill the form for a project, defaulting the timestamp to now.
     */
    public function setProject(Project $project): void
    {
        $this->project = $project;
        $this->status = $project->status?->value;
        $this->changed_at = CarbonImmutable::now()->format('Y-m-d H:i:s');
        $this->version = $project->version;
    }

    /**
     * The bound on changed_at depends on the status being moved to, so the submitted
     * value is passed through. If it is not a real status the enum rule rejects it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ProjectRules::status($this->requireProject(), ProjectStatus::tryFrom($this->status ?? ''));
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
     * Apply the status change, stamping the timestamp the new status owns.
     */
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

    /**
     * The project this form was loaded for.
     */
    private function requireProject(): Project
    {
        return $this->project ?? throw new LogicException('The form has no project; call setProject() first.');
    }
}
