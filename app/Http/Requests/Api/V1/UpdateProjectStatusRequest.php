<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Validation\ProjectRules;
use LogicException;

class UpdateProjectStatusRequest extends ProjectRequest
{
    /**
     * The changed_at bound depends on which status is being moved to, so the submitted
     * value is passed through unvalidated; the enum rule is what rejects a bad one.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...ProjectRules::status(
                $this->boundProject(),
                ProjectStatus::tryFrom($this->stringOrNull('status') ?? ''),
            ),
            ...ProjectRules::version(),
        ];
    }

    public function boundProject(): Project
    {
        return $this->project() ?? throw new LogicException('The status route must bind a project.');
    }

    public function statusEnum(): ProjectStatus
    {
        return ProjectStatus::from($this->validated('status'));
    }
}
