<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Validation\ProjectRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What every project request shares. Only rules() differs between them, and even that is
 * a call into ProjectRules — no request in this namespace defines a rule of its own.
 *
 * Authorization is the can: middleware on the route plus the gate check in ProjectService,
 * so authorize() stays open here rather than checking the same thing a third time in a
 * place a 403 would be harder to trace back to a permission.
 */
abstract class ProjectRequest extends FormRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return ProjectRules::messages();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ProjectRules::attributes();
    }

    /**
     * The bound project, or null on routes without one.
     */
    protected function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    /**
     * Rules are built from raw input, before validation has had a chance to reject it, so
     * "client_name[]=x" would otherwise reach a string parameter and fail as a TypeError.
     * Null here lets the field's own rule turn it into a 422 instead.
     */
    protected function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
